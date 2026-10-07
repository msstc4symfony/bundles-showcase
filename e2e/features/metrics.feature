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

  # FOSElasticaBundle indexes through Elastica 8's index API: PUT <index>/_doc/<id>; metrics-bundle replaces the
  # document id in the path label with ":id", so every order shares one series.
  Scenario: Indexing a new order is measured as an Elastica request
    Given I remember the current metric values
    When I create an order for 5000
    Then within 20 seconds "symfony_elastica_request_success{component='orders',method='PUT',path='orders/_doc/:id'}" grows by 1
