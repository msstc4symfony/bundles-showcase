Feature: SLOs are counted by their strategy and evaluated by the generated rules

  Scenario: Prometheus and Grafana load what slo:generate produced
    Then within 330 seconds Prometheus has evaluated the rule groups of "gateway.create_order", "billing.process_payment", "orders.create_order" and "orders.search_index" without errors
    And within 60 seconds Grafana has 18 dashboards tagged "slo" with distinct uids in the folder "SLO"
    # prometheus-backfill covers its whole 30d window from the start; SLOs without history may have no value yet.
    And the "slo:window:coverage_ratio" rule of "orders.search_index" "availability" evaluates to exactly 1

  Scenario: Each objective is counted where its strategy says
    Given I remember the current metric values
    When I create an order for 5000
    And the order becomes "paid" within 15 seconds
    Then within 20 seconds "symfony_slo_events_total{component='gateway',slo='gateway.create_order',objective='availability',result='good'}" grows by 1
    And within 20 seconds "symfony_slo_events_total{component='gateway',slo='gateway.create_order',objective='latency'}" grows by 1
    And within 20 seconds "symfony_slo_events_total{component='orders',slo='orders.create_order',objective='latency'}" grows by 1
    And within 20 seconds "symfony_slo_events_total{component='orders',instance!='backfill',slo='orders.search_index',objective='availability',result='good'}" grows by 1
    And "symfony_slo_events_total{slo=~'orders.create_order|billing.process_payment',objective='availability'}" has not grown after the next scrape
    And the "slo:sli:ratio" rule of "billing.process_payment" "availability" reads "symfony_messenger_message_handled"
    And the "slo:sli:ratio" rule of "orders.create_order" "availability" reads "symfony_logic_action"
    And the "slo:sli:ratio" rule of "billing.process_payment" "availability" evaluates to at least 0 and at most 1

  Scenario: An SLO operation is a span of the request that ran it
    When I create an order for 5000 with a traceparent
    Then within 15 seconds the "orders" logs of that trace have a profiling span matching "/ > operation orders\.search_index$/"

  # Self-sufficient for --name runs: the order makes sure the SLO has a first event; t0 lies before the stack's first.
  # Not orders.search_index: prometheus-backfill gives it 60 days of history, so it has a value from the start.
  Scenario: An SLO without events in the window is NoData, not 100 %
    Given I create an order for 5000
    And the order becomes "paid" within 15 seconds
    And within 20 seconds "symfony_slo_events_total{slo='orders.create_order'}" has a series
    Then at an instant before the first event of "orders.create_order" while "orders" was scraped, the "slo:sli:ratio" rule of "orders.create_order" "latency" has no value
    And at that instant "slo:error_ratio:rate5m{slo='orders.create_order',objective='latency'}" has no sample

  Scenario: A threshold change starts a new revision that warms up alone
    Given I create an order for 5000
    And the order becomes "paid" within 15 seconds
    And I remember the "slo:sli:ratio" rule value of "gateway.create_order" "latency"
    When the gateway metrics storage gets 1000 bad "latency" events of "gateway.create_order" under the revision "deadbeef"
    Then within 20 seconds "symfony_slo_events_total{slo='gateway.create_order',objective='latency',revision='deadbeef'}" is at least 1000
    And the "slo:sli:ratio" rule value of "gateway.create_order" "latency" is within 0.01 of the remembered one
    And the same rule with revision "deadbeef" added to its filter is at least 0.1 below the remembered one
    And the "slo:window:coverage_ratio" rule of "gateway.create_order" "latency" has no value yet or evaluates to at least 0 and below 1
    And "ALERTS{alertname='SloErrorBudgetBurn',slo='gateway.create_order',severity='ticket'}" has no series

  @chaos
  Scenario: 5xx responses lower the availability SLI and are not latency events
    Given I create an order for 5000
    And the order becomes "paid" within 15 seconds
    And I remember the "slo:sli:ratio" rule value of "gateway.create_order" "availability"
    And I remember the current metric values
    When I stop "orders"
    And I create 5 orders for 5000
    Then the response status is 502
    When I start "orders"
    Then within 30 seconds "orders" readiness is up
    And within 20 seconds "symfony_slo_events_total{component='gateway',slo='gateway.create_order',objective='availability',result='bad'}" grows by 5
    And within 20 seconds "symfony_http_response{component='gateway',route='gateway_create_order',status='502'}" grows by 5
    And "symfony_slo_events_total{component='gateway',slo='gateway.create_order',objective='latency'}" has not grown after the next scrape
    And the "slo:sli:ratio" rule value of "gateway.create_order" "availability" is below the remembered one
    And within 150 seconds "slo:error_ratio:rate5m{slo='gateway.create_order',objective='availability'} > 0" has a series
