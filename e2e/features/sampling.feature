Feature: A gateway that samples half of its new traces
  gateway-sampled is the gateway with sampling.ratio 0.5. Each new trace's id decides (OpenTelemetry OTEP 235): the
  trace is sampled when the last 14 hex digits of its id reach the threshold 80000000000000.

  Scenario: Each new trace is sampled by its id, and only sampled traces reach Tempo
    When I send the baggage probe to the sampling gateway 20 times without a traceparent
    Then every traceresponse is flagged random, and sampled exactly when the trace id reaches the threshold
    And the probes started both sampled and dropped traces
    And within 15 seconds the baggage echo gets every trace with its flags, with "ot=th:8" in tracestate only when sampled
    And within 30 seconds Tempo has every sampled trace with spans of gateway-sampled and orders, and none of the dropped
