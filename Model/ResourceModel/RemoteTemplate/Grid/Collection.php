<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Model\ResourceModel\RemoteTemplate\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\Document;
use MageOS\PageBuilderTemplateImportExport\Model\ResourceModel\RemoteTemplate\Collection as RemoteTemplateCollection;
use Psr\Log\LoggerInterface;
use Magento\Framework\Api\ExtensibleDataInterface;

class Collection extends RemoteTemplateCollection implements SearchResultInterface
{

    /**
     * @var AggregationInterface
     */
    protected AggregationInterface $aggregations;

    public function __construct(
        protected EntityFactoryInterface $entityFactory,
        protected LoggerInterface $logger,
        protected FetchStrategyInterface $fetchStrategy,
        protected ManagerInterface $eventManager,
        protected $mainTable,
        protected $eventPrefix,
        protected $eventObject,
        protected $resourceModel,
        protected $model = Document::class,
        protected $connection = null,
        protected AbstractDb|null $resource = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $connection,
            $resource
        );
        $this->_init($this->model, $this->resourceModel);
        $this->setMainTable($mainTable);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        parent::_resetState();
        $this->_init($this->model, $this->resourceModel);
    }

    /**
     * @return AggregationInterface
     */
    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    /**
     * @param AggregationInterface $aggregations
     * @return $this
     */
    public function setAggregations(AggregationInterface $aggregations): Collection
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * @return SearchCriteriaInterface|null
     */
    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    /**
     * @param SearchCriteriaInterface|null $searchCriteria
     * @return $this
     */
    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null): Collection
    {
        return $this;
    }

    /**
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    /**
     * @param int $totalCount
     * @return $this
     */
    public function setTotalCount(int $totalCount): Collection
    {
        return $this;
    }

    /**
     * @param array|null $items
     * @return $this
     */
    public function setItems(?array $items = null): Collection
    {
        return $this;
    }
}
