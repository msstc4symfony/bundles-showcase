Feature: One trace across HTTP and RabbitMQ
  Request ids are made unique per run, so logs of an earlier run never match.

  Scenario: The client's request id follows the order through every process
    When I create an order for 5000 with request id "trace-showcase-1"
    Then the response header "request-id" is the request id "trace-showcase-1"
    And the order becomes "paid" within 15 seconds
    And within 15 seconds logs with request id "trace-showcase-1" come from "gateway", "orders", "billing-worker" and "orders-worker"
    And the "orders" logs for request id "trace-showcase-1" have request from "showcase:gateway"

  Scenario: A request without a request id gets one
    When I create an order for 5000
    Then the response has a non-empty "request-id" header

  Scenario: A W3C traceparent from the client continues through every process
    When I create an order for 5000 with a traceparent
    Then the order becomes "paid" within 15 seconds
    And within 15 seconds logs with that trace id come from "gateway", "orders", "billing-worker" and "orders-worker"
