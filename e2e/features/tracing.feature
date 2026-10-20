Feature: One W3C trace across HTTP and RabbitMQ
  Trace ids are random per scenario, so logs of an earlier run never match.

  Scenario: The client's trace follows the order through every process
    When I create an order for 5000 with a traceparent
    Then the response header "traceresponse" continues that trace
    And the order becomes "paid" within 15 seconds
    And within 15 seconds logs with that trace id come from:
      | gateway        |
      | orders         |
      | billing-worker |
      | orders-worker  |
    And the "orders" logs of that trace have caller "showcase:gateway"

  Scenario: A request without a traceparent gets a new trace
    When I create an order for 5000
    Then the response has a "traceresponse" header with a new trace id
    And within 15 seconds logs with that trace id come from:
      | gateway |

  Scenario: The logs of one trace rebuild the call tree
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 15 seconds the logs of that trace link the client, gateway, orders, its Action, billing-worker and orders-worker
