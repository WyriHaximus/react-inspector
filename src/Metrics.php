<?php declare(strict_types=1);

namespace WyriHaximus\React\Inspector;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Rx\DisposableInterface;
use Rx\ObserverInterface;
use Rx\Subject\Subject;
use function ApiClients\Tools\Rx\observableFromArray;
use function WyriHaximus\get_in_packages_composer;
use function WyriHaximus\get_in_packages_composer_with_path;

final class Metrics extends Subject implements MetricsStreamInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var string[]
     */
    private $resetGroups;

    /**
     * @var string[][]
     */
    private $resetGroupsMetrics;

    /**
     * @var float
     */
    private $interval;

    /**
     * @var TimerInterface|null
     */
    private $timer;

    /**
     * @var string[]
     */
    private $collectors = [];

    /**
     * @var array
     */
    private $activeCollectors = [];

    /**
     * @param LoopInterface $loop
     * @param string[]      $resetGroups
     * @param float         $interval
     */
    public function __construct(LoopInterface $loop, array $resetGroups, float $interval)
    {
        $this->loop = $loop;
        $this->resetGroups = $resetGroups;
        $this->interval = $interval;

        $this->setUpResetGroups();
        $this->gatherCollectors();
    }

    public function removeObserver(ObserverInterface $observer): bool
    {
        $return = parent::removeObserver($observer);
        if (!$this->hasObservers()) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
            foreach ($this->activeCollectors as $index => $instance) {
                $this->activeCollectors[$index]->cancel();
            }

            $this->activeCollectors = [];
        }

        return $return;
    }

    protected function _subscribe(ObserverInterface $observer): DisposableInterface
    {
        if ($this->timer === null) {
            $this->setUpCollectors();
            $this->timer = $this->loop->addPeriodicTimer($this->interval, function () {
                observableFromArray($this->activeCollectors)->flatMap(function (CollectorInterface $collector) {
                    return $collector->collect();
                })->subscribe(function (Metric $metric) {
                    GlobalState::set($metric->getKey(), $metric->getValue());
                });
                $this->setMemoryMetrics();
                $this->tick();
            });
        }

        return parent::_subscribe($observer);
    }

    private function setUpResetGroups()
    {
        foreach (get_in_packages_composer('extra.react-inspector.reset') as $package => $resetMetricGroups) {
            foreach ($resetMetricGroups as $group => $metrics) {
                foreach ($metrics as $metric) {
                    $this->resetGroupsMetrics[$group][$metric] = $metric;
                }
            }
        }
    }

    private function setUpCollectors(): void
    {
        foreach ($this->collectors as $class) {
            $this->activeCollectors[] = new $class($this->loop);
        }
    }

    private function gatherCollectors(): void
    {
        foreach (get_in_packages_composer_with_path('extra.react-inspector.collectors') as $path => $namespacePrefix) {
            $directory = new \RecursiveDirectoryIterator($path);
            $directory = new \RecursiveIteratorIterator($directory);
            foreach ($directory as $fileinfo) {
                if (!$fileinfo->isFile()) {
                    continue;
                }
                $fileName = $path . str_replace('/', '\\', $fileinfo->getFilename());
                $class = $namespacePrefix . '\\' . substr(substr($fileName, strlen($path)), 0, -4);
                if (class_exists($class) && !(new \ReflectionClass($class))->isInterface()) {
                    $this->collectors[] = $class;
                }
            }
        }
    }

    private function setMemoryMetrics()
    {
        GlobalState::set('memory.external', memory_get_usage(true));
        GlobalState::set('memory.external_peak', memory_get_peak_usage(true));
        GlobalState::set('memory.internal', memory_get_usage());
        GlobalState::set('memory.internal_peak', memory_get_peak_usage());
    }

    private function tick()
    {
        $time = microtime(true);
        $state = GlobalState::get();
        foreach ($this->resetGroups as $group) {
            if (!isset($this->resetGroupsMetrics[$group])) {
                continue;
            }

            foreach ($this->resetGroupsMetrics[$group] as $metric) {
                GlobalState::set($metric, 0);
            }
        }

        foreach ($state as $key => $value) {
            $this->onNext(
                new Metric(
                    $key,
                    (float)$value,
                    $time
                )
            );
        }
    }
}
