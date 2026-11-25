Feature: A scheduled task is measured by its SLO and its run metrics

  # billing-scheduler runs App\Scheduler\ReconcilePayments every 15 s; 45 s covers two runs and a scrape.
  Scenario: Successful runs are good events
    Given I remember the current metric values
    Then within 45 seconds "symfony_slo_events_total{slo='billing.reconcile_payments',objective='availability',result='good'}" grows by at least 1
    And within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='success'}" grows by at least 1

  # A fail window just over one period (runs can start up to 1 s late) and removed once a bad run is scraped: one or
  # two bad runs, as few as the step allows. They are real bad events (see known-issues for the alerts they may fire).
  Scenario: A failed run is a bad event, and every run that starts finishes
    Given I remember the current metric values
    When the billing reconciliation fails for 20 seconds
    Then within 45 seconds "symfony_slo_events_total{slo='billing.reconcile_payments',objective='availability',result='bad'}" grows by at least 1
    When the billing reconciliation is restored
    Then within 20 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='error'}" grows by at least 1
    And within 45 seconds "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke',outcome='success'}" grows by at least 1
    And within 20 seconds "symfony_slo_scheduled_task_started_total{task='App\\Scheduler\\ReconcilePayments::__invoke'}" and "symfony_slo_scheduled_task_finished_total{task='App\\Scheduler\\ReconcilePayments::__invoke'}" have grown equally
