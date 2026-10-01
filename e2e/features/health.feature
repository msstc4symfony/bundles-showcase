Feature: Readiness reflects real dependencies

  Scenario Outline: Every service is ready with its dependencies checked
    When I ask "<service>" for readiness
    Then readiness is up
    And readiness mentions <dependencies>

    Examples:
      | service | dependencies                                                |
      | gateway | "RedisAdapter : cache.app", "Lock store"                    |
      | orders  | "RedisAdapter : cache.app", "DB connection", "Messenger transport (process_payment)" |
      | billing | "RedisAdapter : cache.app", "DB connection", "Messenger transport (process_payment)" |

  @chaos
  Scenario: Losing Redis makes services not ready but still alive
    When I stop "redis"
    Then within 20 seconds "orders" readiness is down
    And "orders" liveliness is up
    When I start "redis"
    Then within 30 seconds "orders" readiness is up

  @chaos
  Scenario: Gateway survives orders being down
    When I stop "orders"
    And I create an order for 5000
    Then the response status is 502
    And within 10 seconds "gateway" readiness is up
    When I start "orders"
    Then within 30 seconds "orders" readiness is up
