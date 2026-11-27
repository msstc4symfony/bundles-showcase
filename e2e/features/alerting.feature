Feature: SLO burn alerts reach their receivers through Alertmanager, and a page inhibits the ticket

  # prometheus-backfill: 60 days of orders.search_index history, 3 % bad in the last 4 days (a 3× burn, below
  # the page thresholds), so the ticket fires once the stack is up. The 5m window group first runs in a wall-clock
  # slot up to 5 min after Prometheus starts, then come the 1m alert group and group_wait: hence 420 s.
  # It resolves after about 6 h of stack uptime (rate6h without history): make down first on an old stack.
  @alerting
  Scenario: A slow burn in the history is delivered as a ticket
    Then the "slo:window:coverage_ratio" rule of "orders.search_index" "availability" evaluates to exactly 1
    And within 420 seconds the "slo-ticket" receiver got a firing "ticket" alert of "orders.search_index" "availability"
    And within 60 seconds Alertmanager has a "ticket" alert of "orders.search_index" "availability"

  # Elasticsearch down: orders are still created (indexing is best effort), every search index event is bad.
  # A live bad series that appears above 0 has no increase(), so one bad event is scraped before the burst.
  # 50 bad events: the 1h ratio is at least (3.6 + 51) / (120 of history + 100 live good + 51) ≈ 20 % > 14.4 %
  # (16 suffice on a fresh stack), and the 5m window holds the burst only. The 100 live good are checked, not
  # assumed: a bigger burst would not need the check, but where Docker's DNS forwards unknown names to an
  # unreachable resolver, an order with Elasticsearch down stalls ~4 s on the name lookup, close to the gateway's
  # 5 s timeout, so every extra order risks a 502.
  # A page left by an earlier run inhibits the ticket until it resolves, hence the 1800 s wait comes first.
  @alerting @chaos
  Scenario: A live breakage is delivered as a page that inhibits the ticket
    Given within 1800 seconds Alertmanager has no "page" alert of "orders.search_index" "availability"
    And within 420 seconds Alertmanager has an uninhibited "ticket" alert of "orders.search_index" "availability"
    And "increase(symfony_slo_events_total{instance!='backfill',slo='orders.search_index',objective='availability',result='good'}[1h])" is at most 100
    And I remember the notifications the alert recorder got so far
    When I stop "elasticsearch"
    And I create an order for 5000
    Then the response status is 201
    And within 20 seconds "symfony_slo_events_total{instance!='backfill',slo='orders.search_index',objective='availability',result='bad'}" has a series
    When I create 50 orders for 5000
    Then the response status is 201
    And within 300 seconds the "slo-page" receiver got a new firing "page" alert of "orders.search_index" "availability"
    And within 60 seconds Alertmanager shows the "ticket" alert of "orders.search_index" "availability" inhibited by its "page" alert
    When I start "elasticsearch"
    # Wait for green|yellow: a run that ends on the red cluster of a fresh start would leave it to the next one.
    Then within 180 seconds "orders" readiness has a line matching "/^Elastica connection \(fos_elastica\.client\.default\) passed \(cluster status: (green|yellow)\)$/"
    And within 10 seconds "orders" readiness is up
