Feature: Spans of every service reach Tempo as one trace
  tracing-bridge-otlp exports the spans with the ids of the logs; Alloy forwards them to Tempo.

  Scenario: An order's trace in Tempo spans the gateway, orders and billing
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 30 seconds Tempo has that trace with spans of:
      | gateway |
      | orders  |
      | billing |
    And every span of that trace in Tempo has its parent in the trace or the client's span as parent
    And the span ids of that trace in Tempo are the span ids of its logs

  # Alloy also ships the logs: they reach Loki once it is back. Exporting resumes after each process's 30 s pause.
  @chaos
  Scenario: Orders go through while the collector is down
    When I stop "alloy"
    And I create an order for 5000
    Then the order becomes "paid" within 15 seconds
    When I start "alloy"
    Then within 60 seconds the "gateway" logs contain "OTLP span export failed"
    And within 90 seconds the trace of a new order reaches Tempo from every service
