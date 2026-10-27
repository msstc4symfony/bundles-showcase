Feature: Spans of every service reach Tempo as one trace
  tracing-bridge-otlp exports the spans with the ids of the logs, and the logs themselves; Alloy forwards the spans to
  Tempo and the logs to Loki's OTLP endpoint.

  Scenario: An order's trace in Tempo spans the gateway, orders and billing
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 30 seconds Tempo has that trace with spans of:
      | gateway |
      | orders  |
      | billing |
    And within 30 seconds every span of that trace in Tempo has its parent in the trace or the client's span
    And within 30 seconds every "orders" request span of that trace is a child of a "gateway" client span
    And within 30 seconds Loki has OTLP log records of that trace from:
      | gateway |
      | orders  |
      | billing |
    And within 30 seconds every span id in the logs of that trace is a span of it in Tempo

  # Alloy also ships the logs: they reach Loki once it is back. Exporting resumes after each process's 30 s pause.
  @chaos
  Scenario: Orders go through while the collector is down
    When I stop "alloy"
    And I create an order for 5000
    Then the order becomes "paid" within 15 seconds
    When I start "alloy"
    Then within 60 seconds the "gateway" logs contain "OTLP span export failed"
    And within 90 seconds the trace of a new order reaches Tempo from every service
    And within 90 seconds the OTLP log records of a new order reach Loki from every service
