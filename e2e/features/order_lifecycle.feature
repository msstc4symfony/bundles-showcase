Feature: Order lifecycle
  An order is created through the gateway, paid by billing asynchronously and its final
  status is visible through the gateway.

  Scenario: An affordable order gets paid
    When I create an order for 5000
    Then the response status is 201
    And the order becomes "paid" within 15 seconds

  Scenario: An order above the billing limit is declined
    When I create an order for 150000
    Then the order becomes "declined" within 15 seconds

  Scenario: An invalid amount is rejected
    When I create an order for 0
    Then the response status is 422

  Scenario: An unknown order is not found
    When I look up the order "0190e0f6-0000-7000-8000-00000000ffff"
    Then the response status is 404
