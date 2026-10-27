Feature: Profiling spans of libraries reach the logs inside the trace

  # Detached HTTP spans (profiling_detached) stay out of the stack, but each gets the client span its request carries in
  # traceparent (tracing-bridge-profiling 1.3); the SQL spans run in trace spans of their own.
  Scenario: SQL and HTTP automatic spans are logged within the order's trace
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 15 seconds the "gateway" logs of that trace have a profiling span matching "/^request gateway_create_order > http POST orders$/"
    And that span is detached and carries a client span under the span around it
    And within 15 seconds the "orders" logs of that trace have a profiling span matching "/ > sql insert orders$/"
    And that span has a trace span id of its own
    And within 15 seconds the "billing-worker" logs of that trace have a profiling span matching "/ > sql insert payment$/"
    And that span has a trace span id of its own
    And within 15 seconds the "orders-worker" logs of that trace have a profiling span matching "/ > sql update orders$/"
    And that span has a trace span id of its own
