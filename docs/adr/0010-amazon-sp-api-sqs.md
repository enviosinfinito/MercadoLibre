# ADR 00010: Amazon SP-API + SQS notifications

## Status
Accepted

## Context
Amazon Selling Partner API uses OAuth, restricted data tokens, and SQS for push notifications rather than simple HTTPS webhooks alone.

## Decision
Implement Amazon via SP-API behind `AmazonConnector`. Use **SQS** (or Amazon’s notification destination into SQS) as the primary event intake, then enqueue domain jobs. Polling/reports fill gaps where notifications are insufficient. Credentials and RDT handling stay inside the Amazon connector.

## Consequences
- Extra infra (SQS consumer, IAM) vs ML webhooks.
- Capability matrix marks Amazon features as partial until each SP-API area is wired.
- FakeConnector remains for local/dev without AWS.
