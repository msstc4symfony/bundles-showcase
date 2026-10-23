Feature: Metrics from every service reach Prometheus

  Scenario: A paid order is visible in metrics
    Given I remember the current metric values
    When I create an order for 5000
    And the order becomes "paid" within 15 seconds
    Then within 20 seconds "symfony_http_request{component='gateway',route='gateway_create_order'}" grows by 1
    And within 20 seconds "symfony_http_connection_request{component='gateway',host='orders',method='POST'}" grows by 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component='orders',message='request_orders_create'}" grows by 1
    And within 20 seconds "symfony_doctrine_query_execute{component='orders',connection='default',type='insert',table='orders'}" grows by 1
    And within 20 seconds "symfony_messenger_message_sent{component='orders',transport='process_payment',message='ProcessPayment'}" grows by 1
    And within 20 seconds "symfony_messenger_message_handled{component='billing',transport='process_payment',message='ProcessPayment',status='handled'}" grows by 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component='billing',message='message_app_message_processpayment'}" grows by 1
    And within 20 seconds "symfony_logic_action_duration_histogram_seconds_count{component='orders',outcome='success'}" grows by 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component='orders',message='action_app_application_order_create_action'}" grows by 1

  # FOSElasticaBundle indexes through Elastica 8's index API: PUT <index>/_doc/<id>; metrics-bundle replaces the
  # document id in the path label with ":id", so every order shares one series.
  Scenario: Indexing a new order is measured as an Elastica request
    Given I remember the current metric values
    When I create an order for 5000
    Then within 20 seconds "symfony_elastica_request_success{component='orders',method='PUT',path='orders/_doc/:id'}" grows by 1

  Scenario: A readiness run is counted per probe and per checker
    Given I remember the current metric values
    When I ask "orders" for readiness
    Then readiness is up
    And within 20 seconds "symfony_healthcheck_probe_run{component='orders',probe='readiness',status='passed'}" grows by 1
    And within 20 seconds "symfony_healthcheck_checker_run{component='orders',probe='readiness',checker='healthcheck.checker.doctrine.dbal.default_connection',status='passed'}" grows by 1
    And within 20 seconds "symfony_healthcheck_checker_duration_histogram_seconds_count{component='orders',probe='readiness',checker='healthcheck.checker.doctrine.dbal.default_connection'}" grows by 1

  Scenario Outline: Every service counts its readiness runs
    Given I remember the current metric values
    When I ask "<service>" for readiness
    Then within 20 seconds "symfony_healthcheck_probe_run{component='<service>',probe='readiness',status='passed'}" grows by 1

    Examples:
      | service |
      | gateway |
      | billing |

  # Gateway's idempotency store reads cache.app, the one pool listed under msstc4symfony_metrics.cache.pools.
  Scenario: Cache pool lookups are counted as hits and misses
    Given I remember the current metric values
    When I create an order for 5000 with idempotency key "showcase-cache-key"
    Then within 20 seconds "symfony_cache_request{component='gateway',pool='cache.app',result='miss'}" grows by 1
    And "symfony_cache_request{component='gateway',pool='cache.app',result='hit'}" has not grown after the next scrape
    Given I remember the current metric values
    When I create an order for 5000 with idempotency key "showcase-cache-key"
    Then within 20 seconds "symfony_cache_request{component='gateway',pool='cache.app',result='hit'}" grows by 1
    And "symfony_cache_request{component='gateway',pool='cache.app',result='miss'}" has not grown after the next scrape

  Scenario: The queue depth of every configured transport is sampled
    Then within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='orders',transport='process_payment'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='orders',transport='payment_processed'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='billing',transport='process_payment'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='billing',transport='payment_processed'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='orders',transport='failed'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth_sampled_at{component='billing',transport='failed'} > time() - 30" has a series
    And within 30 seconds "symfony_messenger_queue_depth{component='billing',transport='process_payment'} == 0" has a series

  # Orders samples the gauge on its HTTP requests and in its worker's idle loop (period PT5S), so the backlog shows up.
  @chaos
  Scenario: A stopped consumer leaves messages in the queue and the depth gauge shows them
    Given within 30 seconds "symfony_messenger_queue_depth{component='orders',transport='process_payment'} == 0" has a series
    When I stop "billing-worker"
    And I create an order for 5000
    Then within 40 seconds "symfony_messenger_queue_depth{component='orders',transport='process_payment'}" is at least 1
    When I start "billing-worker"
    Then the order becomes "paid" within 30 seconds
    And within 40 seconds "symfony_messenger_queue_depth{component='orders',transport='process_payment'} == 0" has a series
