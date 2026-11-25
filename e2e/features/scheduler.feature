Feature: A scheduled task is measured by its SLO and its run metrics

  # billing-scheduler runs App\Scheduler\ReconcilePayments every 15 s; 45 s covers two runs and a scrape.
  Scenario: Successful runs are good events
    Given I remember the current metric values
    Then within 45 seconds "symfony_slo_events_total{slo='billing.reconcile_payments',objective='availability',result='good'}" grows by at least 1
    And within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='success'}" grows by at least 1

  Scenario: Failed runs are bad events
    Given I remember the current metric values
    When the billing reconciliation fails for 40 seconds
    Then within 45 seconds "symfony_slo_events_total{slo='billing.reconcile_payments',objective='availability',result='bad'}" grows by at least 1
    And within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='error'}" grows by at least 1
    And the billing reconciliation is restored

  Scenario: Every run attempt is counted as started
    Given I remember the current metric values
    When the billing reconciliation fails for 40 seconds
    Then within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='error'}" grows by at least 1
    When the billing reconciliation is restored
    Then within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='success'}" grows by at least 1
    And "symfony_slo_scheduled_task_started_total{task='App\\Scheduler\\ReconcilePayments::__invoke'}" has grown at least as much as "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke'}"
