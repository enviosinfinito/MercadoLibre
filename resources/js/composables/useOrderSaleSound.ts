import { useOrderSoundStore } from '@/stores/orderSound';
import { usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

type OrderUpdatedPayload = {
    id: number;
    external_order_id?: string;
    status?: string;
    is_new?: boolean;
};

const DEDUPE_MS = 8_000;
/** Short cha-ching in public/sounds (AAC primary, wav fallback). */
const SOUND_URLS = ['/sounds/new-order.m4a', '/sounds/new-order.wav'] as const;

let sharedAudio: HTMLAudioElement | null = null;
let soundUrlIndex = 0;

function getAudio(): HTMLAudioElement {
    if (!sharedAudio) {
        sharedAudio = new Audio(SOUND_URLS[soundUrlIndex]);
        sharedAudio.preload = 'auto';
        sharedAudio.volume = 0.85;
    }

    return sharedAudio;
}

function playWithFallback(): void {
    const audio = getAudio();
    audio.currentTime = 0;
    void audio.play().catch(() => {
        if (soundUrlIndex < SOUND_URLS.length - 1) {
            soundUrlIndex += 1;
            sharedAudio = null;
            const retry = getAudio();
            retry.currentTime = 0;
            void retry.play().catch(() => {
                // Autoplay blocked or unsupported — ignore
            });
        }
    });
}

/** Call from a user gesture so later autoplay is allowed. */
export function unlockOrderSaleSound(): void {
    try {
        const audio = getAudio();
        const prev = audio.volume;
        audio.volume = 0;
        void audio
            .play()
            .then(() => {
                audio.pause();
                audio.currentTime = 0;
                audio.volume = prev;
            })
            .catch(() => {
                audio.volume = prev;
            });
    } catch {
        // ignore
    }
}

export function playOrderSaleSound(): void {
    try {
        playWithFallback();
    } catch {
        // ignore
    }
}

export function useOrderSaleSound(): void {
    const page = usePage();
    const soundStore = useOrderSoundStore();
    const recentIds = new Map<number, number>();
    let channelName: string | null = null;

    function workspaceId(): number | null {
        const ws = (page.props as { workspace?: { id?: number } | null }).workspace;
        const id = ws?.id;

        return typeof id === 'number' ? id : null;
    }

    function leaveChannel(): void {
        if (channelName && window.Echo) {
            window.Echo.leave(channelName);
        }
        channelName = null;
    }

    function handlePayload(payload: OrderUpdatedPayload): void {
        if (!payload?.is_new || !soundStore.enabled) {
            return;
        }

        const id = Number(payload.id);
        if (!Number.isFinite(id)) {
            return;
        }

        const now = Date.now();
        const prev = recentIds.get(id);
        if (prev !== undefined && now - prev < DEDUPE_MS) {
            return;
        }
        recentIds.set(id, now);
        for (const [key, at] of recentIds) {
            if (now - at > DEDUPE_MS) {
                recentIds.delete(key);
            }
        }

        playOrderSaleSound();
    }

    function subscribe(id: number | null): void {
        leaveChannel();
        if (id === null || !window.Echo) {
            return;
        }

        channelName = `workspace.${id}`;
        window.Echo.private(channelName).listen(
            '.order.updated',
            (payload: OrderUpdatedPayload) => {
                handlePayload(payload);
            },
        );
    }

    function onFirstGesture(): void {
        unlockOrderSaleSound();
        window.removeEventListener('pointerdown', onFirstGesture);
        window.removeEventListener('keydown', onFirstGesture);
    }

    onMounted(() => {
        subscribe(workspaceId());
        window.addEventListener('pointerdown', onFirstGesture, { once: true });
        window.addEventListener('keydown', onFirstGesture, { once: true });
    });

    watch(
        () => workspaceId(),
        (id) => {
            subscribe(id);
        },
    );

    onUnmounted(() => {
        leaveChannel();
        window.removeEventListener('pointerdown', onFirstGesture);
        window.removeEventListener('keydown', onFirstGesture);
    });
}
