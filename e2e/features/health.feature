Feature: Readiness reflects real dependencies

  Scenario Outline: Every service is ready with its dependencies checked
    When I ask "<service>" for readiness
    Then readiness is up
    And readiness mentions <dependencies>

    Examples:
      | service | dependencies                                                                         |
      | gateway | "RedisAdapter : cache.app", "Lock store (lock.default)"                              |
      | orders  | "RedisAdapter : cache.app", "DB connection", "Messenger transport (process_payment)" |
      | billing | "RedisAdapter : cache.app", "DB connection", "Messenger transport (process_payment)" |

  # A single node cannot place replicas: yellow is the healthy state here, green would need a second node.
  Scenario: Orders probes Elasticsearch through the FOSElasticaBundle client
    When I ask "orders" for readiness
    Then readiness is up
    And readiness has a line matching "/^Elastica connection \(fos_elastica\.client\.default\) passed \(cluster status: (green|yellow)\)$/"

  @chaos
  Scenario: Losing Redis makes services not ready but still alive
    When I stop "redis"
    Then within 20 seconds "orders" readiness is down
    And "orders" liveliness is up
    When I create an order for 5000 with idempotency key "chaos-key"
    Then the response status is 503
    When I start "redis"
    Then within 30 seconds "orders" readiness is up
    # Long-running workers must reconnect their metrics storage, not stay silent until a restart.
    Given I remember the current metric values
    When I create an order for 5000
    Then the order becomes "paid" within 15 seconds
    And within 20 seconds "symfony_messenger_message_handled{component='billing',status='handled'}" grows by 1

  @chaos
  Scenario: Gateway survives orders being down
    When I stop "orders"
    And I create an order for 5000
    Then the response status is 502
    And within 10 seconds "gateway" readiness is up
    When I start "orders"
    Then within 30 seconds "orders" readiness is up

  # Non-critical, unlike Redis: orders are created and served without Elasticsearch (indexing is best effort),
  # so its failure is a readiness warning, "<label> failed (<reason>)", and readiness stays up.
  @chaos
  Scenario: Losing Elasticsearch leaves orders ready with a warning
    When I stop "elasticsearch"
    Then within 20 seconds "orders" readiness is up with a warning matching "/^Elastica connection \(fos_elastica\.client\.default\) failed \(.+\)$/"
    And "orders" liveliness is up
    When I create an order for 5000
    Then the response status is 201
    And the order becomes "paid" within 15 seconds
    When I start "elasticsearch"
    # Wait for green|yellow: a run that ends on the red cluster of a fresh start would leave it to the next one.
    Then within 90 seconds "orders" readiness has a line matching "/^Elastica connection \(fos_elastica\.client\.default\) passed \(cluster status: (green|yellow)\)$/"
    And within 10 seconds "orders" readiness is up
