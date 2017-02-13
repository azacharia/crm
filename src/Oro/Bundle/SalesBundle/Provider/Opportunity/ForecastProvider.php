<?php

namespace Oro\Bundle\SalesBundle\Provider\Opportunity;

use Symfony\Bridge\Doctrine\RegistryInterface;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

use Oro\Bundle\CurrencyBundle\Query\CurrencyQueryBuilderTransformerInterface;
use Oro\Bundle\QueryDesignerBundle\QueryDesigner\FilterProcessor;
use Oro\Bundle\EntityExtendBundle\Provider\EnumValueProvider;
use Oro\Bundle\UserBundle\Entity\Repository\UserRepository;
use Oro\Bundle\DashboardBundle\Filter\WidgetProviderFilter;
use Oro\Bundle\DashboardBundle\Model\WidgetOptionBag;
use Oro\Bundle\SalesBundle\Entity\Repository\OpportunityRepository;

class ForecastProvider
{
    /** @var RegistryInterface */
    protected $doctrine;

    /** @var WidgetProviderFilter */
    protected $widgetProviderFilter;

    /** @var EnumValueProvider */
    protected $enumProvider;

    /** @var  array */
    protected $data;

    /** @var  array */
    protected $statuses;

    /** @var FilterProcessor */
    protected $filterProcessor;

    /** @var CurrencyQueryBuilderTransformerInterface  */
    protected $qbTransformer;

    /** @var array */
    protected static $fieldsAuditMap = [
        'status'       => ['old' => 'oldText', 'new' => 'newText'],
        'owner'        => ['old' => 'oldText', 'new' => 'newText'],
        'closeDate'    => ['old' => 'oldDatetime', 'new' => 'newDatetime'],
        'probability'  => ['old' => 'oldFloat', 'new' => 'newFloat'],
        'budgetAmount' => ['old' => 'oldFloat', 'new' => 'newFloat'],
    ];

    /**
     * @param RegistryInterface $doctrine
     * @param WidgetProviderFilter $widgetProviderFilter
     * @param EnumValueProvider $enumProvider
     * @param FilterProcessor $filterProcessor
     * @param CurrencyQueryBuilderTransformerInterface $qbTransformer
     */
    public function __construct(
        RegistryInterface $doctrine,
        WidgetProviderFilter $widgetProviderFilter,
        EnumValueProvider $enumProvider,
        FilterProcessor $filterProcessor,
        CurrencyQueryBuilderTransformerInterface $qbTransformer
    ) {
        $this->doctrine             = $doctrine;
        $this->widgetProviderFilter = $widgetProviderFilter;
        $this->enumProvider         = $enumProvider;
        $this->filterProcessor      = $filterProcessor;
        $this->qbTransformer        = $qbTransformer;
    }

    /**
     * @param WidgetOptionBag $widgetOptions
     * @param \DateTime|null $start
     * @param \DateTime|null $end
     * @param \DateTime|null $moment
     * @param array|null     $queryFilter
     *
     * @return array ['inProgressCount' => <int>, 'budgetAmount' => <double>, 'weightedForecast' => <double>]
     */
    public function getForecastData(
        WidgetOptionBag $widgetOptions,
        \DateTime $start = null,
        \DateTime $end = null,
        \DateTime $moment = null,
        array $queryFilter = null
    ) {
        $ownerIds = $this->widgetProviderFilter->getOwnerIds($widgetOptions);
        $filters = isset($queryFilter['definition']['filters'])
            ? $queryFilter['definition']['filters']
            : [];
        $key     = $this->getDataHashKey($ownerIds, $start, $end, $moment, $filters);
        if (!isset($this->data[$key])) {
            if (!$moment) {
                $this->data[$key] = $this->getCurrentData($widgetOptions, $start, $end, $filters);
            } else {
                $this->data[$key] = $this->getMomentData($ownerIds, $moment, $start, $end, $filters);
            }
        }

        return $this->data[$key];
    }

    /**
     * @param WidgetOptionBag $widgetOptions
     * @param \DateTime $start
     * @param \DateTime $end
     * @param array     $filters
     *
     * @return array
     */
    protected function getCurrentData(
        WidgetOptionBag $widgetOptions,
        \DateTime $start = null,
        \DateTime $end = null,
        array $filters = []
    ) {
        $clonedStart = $start ? clone $start : null;
        $clonedEnd   = $end ? clone $end : null;
        $alias       = 'o';
        $qb          = $this->getOpportunityRepository()->getForecastQB($this->qbTransformer, $alias);

        $qb = $this->filterProcessor
            ->process($qb, 'Oro\Bundle\SalesBundle\Entity\Opportunity', $filters, $alias);

        $this->applyDateFiltering($qb, 'o.closeDate', $clonedStart, $clonedEnd);

        return $this->widgetProviderFilter->filter($qb, $widgetOptions)->getOneOrNullResult();
    }

    /**
     * @param array          $ownerIds
     * @param \DateTime      $moment
     * @param \DateTime|null $start
     * @param \DateTime|null $end
     * @param array          $filters
     *
     * @return array
     */
    protected function getMomentData(
        array $ownerIds,
        \DateTime $moment,
        \DateTime $start = null,
        \DateTime $end = null,
        array $filters = []
    ) {
        // clone datetimes as doctrine modifies their timezone which breaks stuff
        $moment = clone $moment;
        $start  = $start ? clone $start : null;
        $end    = $end ? clone $end : null;

        $qb = $this->getDataAuditQueryBuilder($moment);
        $this->applyHistoryDateFiltering($qb, $start, $end);

        if ($ownerIds) {
            $this->addOwnersToDataAuditQB($qb, $ownerIds);
        }
        // need to join opportunity to properly apply acl permissions
        $qb->join('OroSalesBundle:Opportunity', 'o', Join::WITH, 'a.objectId = o.id');
        if ($filters) {
            $this->filterProcessor
                ->process($qb, 'Oro\Bundle\SalesBundle\Entity\Opportunity', $filters, 'o');
        }

        $result = $this->widgetProviderFilter->applyAcl($qb)->getArrayResult();

        return $this->getAggregatedResult($result);
    }

    /**
     * @param QueryBuilder $qb
     * @param \DateTime    $start
     * @param \DateTime    $end
     */
    protected function applyHistoryDateFiltering(QueryBuilder $qb, \DateTime $start = null, \DateTime $end = null)
    {
        if (!$start && !$end) {
            return;
        }

        $closeDateFieldQb = $this->getAuditFieldRepository()->createQueryBuilder('afch')
            ->select('afch.newDate')
            ->where('afch.id = MAX(afc.id)');
        $this->applyDateFiltering($closeDateFieldQb, 'afch.newDate', $start, $end);

        $qb->andHaving($qb->expr()->exists($closeDateFieldQb->getDQL()));
        foreach ($closeDateFieldQb->getParameters() as $parameter) {
            $qb->setParameter(
                $parameter->getName(),
                $parameter->getValue(),
                $parameter->getType()
            );
        }
    }

    /**
     * @param QueryBuilder   $qb
     * @param string         $field
     * @param \DateTime|null $start
     * @param \DateTime|null $end
     */
    protected function applyDateFiltering(
        QueryBuilder $qb,
        $field,
        \DateTime $start = null,
        \DateTime $end = null
    ) {
        if ($start) {
            $qb
                ->andWhere(sprintf('%s >= :start', $field))
                ->setParameter('start', $start);
        }
        if ($end) {
            $qb
                ->andWhere(sprintf('%s <= :end', $field))
                ->setParameter('end', $end);
        }
    }

    /**
     * @return OpportunityRepository
     */
    protected function getOpportunityRepository()
    {
        return $this->doctrine->getRepository('OroSalesBundle:Opportunity');
    }

    /**
     * @return EntityRepository
     */
    protected function getAuditFieldRepository()
    {
        return $this->doctrine->getRepository('OroDataAuditBundle:AuditField');
    }

    /**
     * @return EntityRepository
     */
    protected function getAuditRepository()
    {
        return $this->doctrine->getRepository('OroDataAuditBundle:Audit');
    }

    /**
     * @return UserRepository
     */
    protected function getUserRepository()
    {
        return $this->doctrine->getRepository('OroUserBundle:User');
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    protected function getStatusTextValue($key)
    {
        if (null === $this->statuses) {
            $this->statuses = $this->enumProvider->getEnumChoicesByCode('opportunity_status');
        }

        return $this->statuses[$key];
    }

    /**
     * @param array          $ownerIds
     * @param \DateTime|null $start
     * @param \DateTime|null $end
     * @param \DateTime|null $moment
     * @param array          $filters
     *
     * @return string
     */
    protected function getDataHashKey(
        array $ownerIds,
        \DateTime $start = null,
        \DateTime $end = null,
        \DateTime $moment = null,
        array $filters = []
    ) {
        return md5(
            serialize(
                [
                    'ownerIds' => $ownerIds,
                    'start'    => $start,
                    'end'      => $end,
                    'moment'   => $moment,
                    'filters'  => $filters
                ]
            )
        );
    }

    /**
     * @param \DateTime $moment
     *
     * @return QueryBuilder
     */
    protected function getDataAuditQueryBuilder(\DateTime $moment)
    {
        $qb = $this->getAuditRepository()->createQueryBuilder('a');
        $qb
            ->select(<<<SELECT
(SELECT afps.newFloat FROM OroDataAuditBundle:AuditField afps WHERE afps.id = MAX(afp.id)) AS probability,
(SELECT afpb.newFloat FROM OroDataAuditBundle:AuditField afpb WHERE afpb.id = MAX(afb.id)) AS budgetAmount
SELECT
            )
            ->leftJoin('a.fields', 'afca', Join::WITH, 'afca.field = :closedAtField')
            ->leftJoin('a.fields', 'afc', Join::WITH, 'afc.field = :closeDateField')
            ->leftJoin('a.fields', 'afp', Join::WITH, 'afp.field = :probabilityField')
            ->leftJoin('a.fields', 'afb', Join::WITH, 'afb.field = :budgetAmountField')
            ->where('a.objectClass = :objectClass AND a.loggedAt < :moment')
            ->groupBy('a.objectId')
            ->having(<<<HAVING
NOT EXISTS(
    SELECT
        afcah.newDatetime
    FROM OroDataAuditBundle:AuditField afcah
    WHERE
        afcah.id = MAX(afca.id)
        AND afcah.newDatetime IS NOT NULL
)
AND EXISTS(
    SELECT
        afph.newFloat
    FROM OroDataAuditBundle:AuditField afph
    WHERE
        afph.id = MAX(afp.id)
)
HAVING
            )
            ->setParameters([
                'objectClass'           => 'Oro\Bundle\SalesBundle\Entity\Opportunity',
                'closedAtField'         => 'closedAt',
                'closeDateField'        => 'closeDate',
                'probabilityField'      => 'probability',
                'budgetAmountField'     => 'budgetAmount',
                'moment'                => $moment,
            ]);

        return $qb;
    }

    protected function addOwnersToDataAuditQB(QueryBuilder $qb, $ownerIds)
    {
        $qb
            ->join('a.fields', 'afo', Join::WITH, 'afo.field = :ownerField')
            ->andHaving(<<<HAVING
EXISTS(
    SELECT
        afoh.newText
    FROM OroDataAuditBundle:AuditField afoh
    WHERE
        afoh.id = MAX(afo.id)
        AND afoh.newText IN (SELECT u.username FROM OroUserBundle:User u WHERE u.id IN (:ownerIds))
)
HAVING
            )
            ->setParameter('ownerField', 'owner')
            ->setParameter('ownerIds', $ownerIds);
    }

    /**
     * @param array $result
     *
     * @return mixed
     */
    protected function getAggregatedResult(array $result)
    {
        return array_reduce(
            $result,
            function ($result, $row) {
                $result['inProgressCount']++;
                $result['budgetAmount'] += $row['budgetAmount'];
                $result['weightedForecast'] += $row['budgetAmount'] * $row['probability'];

                return $result;
            },
            ['inProgressCount' => 0, 'budgetAmount' => 0, 'weightedForecast' => 0]
        );
    }
}
