Feature: Long-running workers do not leak state
  RoadRunner keeps 2 gateway workers and messenger:consume keeps one process, so these
  requests and messages reuse the same PHP processes.

  Scenario: Every request on reused RoadRunner workers gets its own runtime id
    When I send 6 order lookups with request ids "reset-1" to "reset-6"
    Then within 15 seconds the gateway logs show 6 distinct runtime ids for those request ids
    And no gateway log line of the "reset-2" runtime carries another request id

  Scenario: Every consumed message gets its own trace
    When I create orders with request ids "msg-1", "msg-2" and "msg-3"
    Then within 15 seconds no "billing-worker" log line of the "msg-2" runtime carries another request id
