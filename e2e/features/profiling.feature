Feature: Profiling spans of libraries reach the logs inside the trace

  # Detached HTTP spans (profiling_detached) run in the trace span around them, so only the SQL spans get their own.
  Scenario: SQL and HTTP automatic spans are logged within the order's trace
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 15 seconds the "gateway" logs of that trace have a profiling span matching "/^request gateway_create_order > http POST orders$/"
    And that span is detached and runs in the trace span around it
    And within 15 seconds the "orders" logs of that trace have a profiling span matching "/ > sql insert orders$/"
    And that span has a trace span id of its own
    And within 15 seconds the "billing-worker" logs of that trace have a profiling span matching "/ > sql insert payment$/"
    And that span has a trace span id of its own
    And within 15 seconds the "orders-worker" logs of that trace have a profiling span matching "/ > sql update orders$/"
    And that span has a trace span id of its own
