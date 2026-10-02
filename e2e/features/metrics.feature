Feature: Metrics from every service reach Prometheus

  Scenario: A paid order is visible in metrics
    Given I remember the current metric values
    When I create an order for 5000
    And the order becomes "paid" within 15 seconds
    Then within 20 seconds "symfony_http_request{component='gateway',route='gateway_create_order'}" grows by 1
    And within 20 seconds "symfony_http_connection_request{component='gateway',host='orders',method='POST'}" grows by 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component='orders',message='request_orders_create'}" grows by 1
    And within 20 seconds "symfony_doctrine_query_execute{component='orders'}" grows by at least 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component='billing',message='message_app_message_processpayment'}" grows by 1
