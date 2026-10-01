Feature: Idempotent order creation
  Keys are made unique per run, so a key from an earlier run never answers for this one.

  Scenario: Repeating a request with the same key returns the same order
    When I create an order for 5000 with idempotency key "showcase-key-1"
    And I create an order for 5000 with idempotency key "showcase-key-1"
    Then both responses carry the same order id

  Scenario: Concurrent duplicates still create one order
    When I send 5 concurrent order requests for 5000 with idempotency key "showcase-key-2"
    Then all responses carry the same order id
