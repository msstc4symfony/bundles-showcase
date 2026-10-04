<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Msstc4Symfony\HealthCheckBundle\HealthCheckBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Msstc4Symfony\LoggerBundle\LoggerBundle::class => ['all' => true],
    Msstc4Symfony\ProfilingBundle\ProfilingBundle::class => ['all' => true],
    Msstc4Symfony\MetricsBundle\MetricsBundle::class => ['all' => true],
    Msstc4Symfony\MetricsBridgeProfiling\MetricsBridgeProfilingBundle::class => ['all' => true],
    Msstc4Symfony\TracingBundle\TracingBundle::class => ['all' => true],
    Baldinof\RoadRunnerBundle\BaldinofRoadRunnerBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
];
