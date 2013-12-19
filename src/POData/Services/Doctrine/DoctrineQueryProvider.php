<?php

namespace POData\Services\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\QueryBuilder;
use POData\Providers\Query\QueryResult;
use POData\Providers\Query\QueryType;
use POData\UriProcessor\QueryProcessor\ExpressionParser\FilterInfo;
use POData\UriProcessor\QueryProcessor\SkipTokenParser\InternalSkipTokenInfo;
use POData\UriProcessor\ResourcePathProcessor\SegmentParser\KeyDescriptor;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Query\IQueryProvider;
use POData\Common\ODataException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;

class DoctrineQueryProvider implements IQueryProvider
{
    /**
     * Handle to connection to Database     
     */
    private $_connectionHandle = null;

    /**
     * Reference to the custom expression provider
     * 
     * @var DoctrineDSExpressionProvider
     */
    private $_doctrineExpressionProvider;

	private $_doctrine;

    /**
     * Constructs a new instance of DoctrineQueryProvider
     * 
     */
    public function __construct(Registry $_doctrine)
    {
    	$this->_doctrine = $_doctrine;
    }

    /**
     * (non-PHPdoc)
     * @see POData\Providers\Query.IQueryProvider::canApplyQueryOptions()
     */
    public function handlesOrderedPaging()
    {
        return true;
    }

    /**
     * (non-PHPdoc)
     * @see POData\Providers\Query.IQueryProvider::getExpressionProvider()
     */
    public function getExpressionProvider()
    {
    	if (is_null($this->_doctrineExpressionProvider)) {
    		$this->_doctrineExpressionProvider = new DoctrineDSExpressionProvider();
    	}

    	return $this->_doctrineExpressionProvider;
    }


	/**
	 * Gets collection of entities belongs to an entity set
	 * IE: http://host/EntitySet
	 *  http://host/EntitySet?$skip=10&$top=5&filter=Prop gt Value
	 *
	 * @param QueryType $queryType indicates if this is a query for a count, entities, or entities with a count
	 * @param ResourceSet $resourceSet The entity set containing the entities to fetch
	 * @param FilterInfo $filterInfo represents the $filter parameter of the OData query.  NULL if no $filter specified
	 * @param mixed $orderBy sorted order if we want to get the data in some specific order
	 * @param int $top number of records which  need to be skip
	 * @param String $skip value indicating what number of records to skip
	 * @param String $skipToken value indicating what record to skip past
	 *
	 * @return QueryResult
	 */
    public function getResourceSet(
		QueryType $queryType,
		ResourceSet $resourceSet,
		$filterInfo = null,
		$orderBy = null,
		$top = null,
		$skip = null,
		$skipToken = null
	) {
		$alias = 'r';
		$queryBuilder = $this->queryResourceSet($queryType, $resourceSet, $alias, $filterInfo, $skipToken);

		return $this->prepareQueryResult($queryBuilder, $queryType, $resourceSet, $alias, $top, $skip);
    }


	/**
	 * @param QueryType $queryType
	 * @param ResourceSet $resourceSet
	 * @param null $filterInfo
	 * @param null $orderBy
	 * @return QueryBuilder
	 */
	private function queryResourceSet(
		QueryType $queryType,
		ResourceSet $resourceSet,
		$alias,
		$filterInfo = null,
		InternalSkipTokenInfo $skipToken = null
	) {
		/* @var $queryBuilder \Doctrine\ORM\QueryBuilder */
		$queryBuilder = $this->
			_doctrine
			->getManager()
			->createQueryBuilder();

		$resourceSetName = $resourceSet->getName();
		$keys            = $resourceSet->getResourceType()->getKeyProperties();
		$key             = key($keys);

		try {
			$queryBuilder->from($resourceSet->getResourceType()->getNamespace() .':'. $resourceSetName, 'r');

			if ($filterInfo != null) {
				$queryBuilder->where($filterInfo);
			}

			if ($skipToken != null) {
				$token = $skipToken->getSkipTokenInfo()->getOrderByKeysInToken();
				$queryBuilder
					->where($queryBuilder->expr()->gt($alias .'.'. $key, $token[0][0]));
			}

			return $queryBuilder;
		}
		catch (\Exception $e ) {
			throw ODataException::createInternalServerError($e->getMessage());
		}
	}

	private function prepareQueryResult(
		QueryBuilder $queryBuilder,
		QueryType $queryType,
		ResourceSet $resourceSet,
		$alias,
		$top = null,
		$skip = null,
		$skipToken = null
	) {
		$queryResult = new QueryResult();
		$keys        = $resourceSet->getResourceType()->getKeyProperties();
		$key         = key($keys);

		try {
			if (count($keys) > 1) {
				throw new \Exception('Cannot currently handle composite keys');
			}

			if ($queryType == QueryType::COUNT() || $queryType == QueryType::ENTITIES_WITH_COUNT()) {
				$queryBuilder->addSelect($queryBuilder->expr()->count($alias .'.'. $key));

				$query                = $queryBuilder->getQuery();
				$queryResult->count   = $query->getSingleScalarResult();

				if ($queryType == QueryType::COUNT()) {
					$queryResult->count = QueryResult::adjustCountForPaging($queryResult->count, $top, $skip);
				}
			}

			if ($queryType == QueryType::ENTITIES() || $queryType == QueryType::ENTITIES_WITH_COUNT()) {
				$queryBuilder->addSelect($alias);

				if ($top) {
					$queryBuilder->setMaxResults(intval($top));
				}

				if ($skip) {
					$queryBuilder->setFirstResult(intval($skip));
				}

				$query = $queryBuilder->getQuery();
				$queryResult->results = $query->getResult();
			}

			return $queryResult;
		}
		catch (\Exception $e ) {
			throw ODataException::createInternalServerError($e->getMessage());
		}
	}

    /**
     * Gets an entity instance from an entity set identifed by a key
     * 
     * @param ResourceSet   $resourceSet   The entity set from which 
     *                                     an entity needs to be fetched
     * @param KeyDescriptor $keyDescriptor The key to identify the entity to be fetched
     * 
     * @return object|null Returns entity instance if found else null
     */
    public function getResourceFromResourceSet(ResourceSet $resourceSet, KeyDescriptor $keyDescriptor)
    {
		$resourceSetName =  $resourceSet->getName();

		try {
			/* @var $builder \Doctrine\ORM\QueryBuilder */
			$builder = $this->
				_doctrine
				->getManager()
				->createQueryBuilder();

			$builder
				->select('r')
				->from('VivaApolloBundle:'. $resourceSetName, 'r')
				->setMaxResults(1);

			//throw ODataException::createInternalServerError('(DoctrineQueryProvider) Unknown resource set ' . $resourceSetName . '! Contact service provider');

			$namedKeyValues = $keyDescriptor->getValidatedNamedValues();

			foreach ($namedKeyValues as $key => $value) {
				$builder
					->where('r.'. $key . ' = ' . $value[0]);
			}

			$query = $builder->getQuery();

			$result = $query->getResult();

			return isset($result[0]) ? $result[0] : null;
		}
		catch (\Exception $e ) {
			throw ODataException::createInternalServerError($e->getMessage());
		}
    }

    /**
     * Get related resource for a resource
     * 
     * @param ResourceSet      $sourceResourceSet    The source resource set
     * @param mixed            $sourceEntityInstance The source resource
     * @param ResourceSet      $targetResourceSet    The resource set of 
     *                                               the navigation property
     * @param ResourceProperty $targetProperty       The navigation property to be 
     *                                               retrieved
     * 
     * @return object|null The related resource if exists else null
     */
    public function getRelatedResourceReferenceOLD(ResourceSet $sourceResourceSet,
        $sourceEntityInstance, 
        ResourceSet $targetResourceSet,
        ResourceProperty $targetProperty
    ) {
        $result = null;
        $srcClass = get_class($sourceEntityInstance);
        $navigationPropName = $targetProperty->getName();
        if ($srcClass === 'Order') {
            if ($navigationPropName === 'Customer') {
                if (empty($sourceEntityInstance->CustomerID)) {
                    $result = null;
                } else {                    
                    $query = "SELECT * FROM Customers WHERE CustomerID = '$sourceEntityInstance->CustomerID'";                
                    $stmt = sqlsrv_query($this->_connectionHandle, $query);
                    if ($stmt === false) {
                        $errorAsString = self::_getSQLSRVError();
						throw ODataException::createInternalServerError($errorAsString);
                    }

                    if (!sqlsrv_has_rows($stmt)) {
                        $result =  null;
                    }

                    $result = $this->_serializeCustomer(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));
                }
            } else {
                die('Customer does not have navigation porperty with name: ' . $navigationPropName);
            }            
        } else if ($srcClass === 'Order_Details') {
            if ($navigationPropName === 'Order') {
                if (empty($sourceEntityInstance->OrderID)) {
                    $result = null;
                } else {
                    $query = "SELECT * FROM Orders WHERE OrderID = $sourceEntityInstance->OrderID";
                    $stmt = sqlsrv_query($this->_connectionHandle, $query);
                    if ($stmt === false) {
                        $errorAsString = self::_getSQLSRVError();
						throw ODataException::createInternalServerError($errorAsString);
                    }
                    
                    if (!sqlsrv_has_rows($stmt)) {
                        $result =  null;
                    }
                    
                    $result = $this->_serializeOrder(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));
                }
            } else {
                die('Order_Details does not have navigation porperty with name: ' . $navigationPropName);
            }
        } 

        return $result;
    }

	/**
	 * Get related resource set for a resource
	 * IE: http://host/EntitySet(1L)/NavigationPropertyToCollection
	 * http://host/EntitySet?$expand=NavigationPropertyToCollection
	 *
	 * @param QueryType $queryType indicates if this is a query for a count, entities, or entities with a count
	 * @param ResourceSet $sourceResourceSet The entity set containing the source entity
	 * @param object $sourceEntityInstance The source entity instance.
	 * @param ResourceSet $targetResourceSet The resource set of containing the target of the navigation property
	 * @param ResourceProperty $targetProperty The navigation property to retrieve
	 * @param FilterInfo $filter represents the $filter parameter of the OData query.  NULL if no $filter specified
	 * @param mixed $orderBy sorted order if we want to get the data in some specific order
	 * @param int $top number of records which  need to be skip
	 * @param String $skip value indicating what records to skip
	 *
	 * @return QueryResult
	 *
	 */
	public function getRelatedResourceSet(
		QueryType $queryType,
		ResourceSet $sourceResourceSet,
		$sourceEntityInstance,
		ResourceSet $targetResourceSet,
		ResourceProperty $targetProperty,
		$filter = null,
		$orderBy = null,
		$top = null,
		$skip = null
	) {
		// Let doctrine handle fetching the relation
		$method = 'get'.Container::camelize($targetProperty->getName());
		$results = $sourceEntityInstance->$method();

		$queryResult = new QueryResult();
		$queryResult->results = $results->toArray();

		return $queryResult;
	}


	/**
	 * Get related resource set for a resource
	 *
	 * @param ResourceSet      $sourceResourceSet    The source resource set
	 * @param mixed            $sourceEntityInstance The resource
	 * @param ResourceSet      $targetResourceSet    The resource set of
	 *                                               the navigation property
	 * @param ResourceProperty $targetProperty       The navigation property to be
	 *                                               retrieved
	 * @param string           $filterOption         Contains the filter condition
	 *                                               to append with query.
	 * @param string           $select               For future purpose,no need to pass it
	 * @param string           $orderby              For future purpose,no need to pass it
	 * @param string           $top                  For future purpose,no need to pass it
	 * @param string           $skip                 For future purpose,no need to pass it
	 *
	 * @return object[] Array of related resource if exists, if no
	 *                                related resources found returns empty array
	 */
	public function  getRelatedResourceSetOLD(ResourceSet $sourceResourceSet,
											  $sourceEntityInstance,
											  ResourceSet $targetResourceSet,
											  ResourceProperty $targetProperty,
											  $filterOption = null,
											  $select=null, $orderby=null, $top=null, $skip=null
	) {
		$result = array();
		$srcClass = get_class($sourceEntityInstance);
		$navigationPropName = $targetProperty->getName();
		if ($srcClass === 'Customer') {
			if ($navigationPropName === 'Orders') {
				$query = "SELECT * FROM Orders WHERE CustomerID = '$sourceEntityInstance->CustomerID'";
				if ($filterOption != null) {
					$query .= ' AND ' . $filterOption;
				}
				$stmt = sqlsrv_query($this->_connectionHandle, $query);
				if ($stmt === false) {
					$errorAsString = self::_getSQLSRVError();
					throw ODataException::createInternalServerError($errorAsString);
				}

				$result = $this->_serializeOrders($stmt);
			} else {
				die('Customer does not have navigation porperty with name: ' . $navigationPropName);
			}
		} else if ($srcClass === 'Order') {
			if ($navigationPropName === 'Order_Details') {
				$query = "SELECT * FROM [Order Details] WHERE OrderID = $sourceEntityInstance->OrderID";
				if ($filterOption != null) {
					$query .= ' AND ' . $filterOption;
				}
				$stmt = sqlsrv_query($this->_connectionHandle, $query);
				if ($stmt === false) {
					$errorAsString = self::_getSQLSRVError();
					throw ODataException::createInternalServerError($errorAsString);
				}

				$result = $this->_serializeOrderDetails($stmt);
			} else {
				die('Order does not have navigation porperty with name: ' . $navigationPropName);
			}
		}

		return $result;
	}


	/**
	 * Gets a related entity instance from an entity set identifed by a key
	 *
	 * @param ResourceSet      $sourceResourceSet    The entity set related to
	 *                                               the entity to be fetched.
	 * @param object           $sourceEntityInstance The related entity instance.
	 * @param ResourceSet      $targetResourceSet    The entity set from which
	 *                                               entity needs to be fetched.
	 * @param ResourceProperty $targetProperty       The metadata of the target
	 *                                               property.
	 * @param KeyDescriptor    $keyDescriptor        The key to identify the entity
	 *                                               to be fetched.
	 *
	 * @return object|null Returns entity instance if found else null
	 */
	public function  getResourceFromRelatedResourceSetOLD(ResourceSet $sourceResourceSet,
														  $sourceEntityInstance,
														  ResourceSet $targetResourceSet,
														  ResourceProperty $targetProperty,
														  KeyDescriptor $keyDescriptor
	) {
		$result = array();
		$srcClass = get_class($sourceEntityInstance);
		$navigationPropName = $targetProperty->getName();
		$key = null;
		foreach ($keyDescriptor->getValidatedNamedValues() as $keyName => $valueDescription) {
			$key = $key . $keyName . '=' . $valueDescription[0] . ' and ';
		}

		$key = rtrim($key, ' and ');
		if ($srcClass === 'Customer') {
			if ($navigationPropName === 'Orders') {
				$query = "SELECT * FROM Orders WHERE CustomerID = '$sourceEntityInstance->CustomerID' and $key";
				$stmt = sqlsrv_query($this->_connectionHandle, $query);
				if ($stmt === false) {
					$errorAsString = self::_getSQLSRVError();
					throw ODataException::createInternalServerError($errorAsString);
				}

				$result = $this->_serializeOrders($stmt);
			} else {
				die('Customer does not have navigation porperty with name: ' . $navigationPropName);
			}
		} else if ($srcClass === 'Order') {
			if ($navigationPropName === 'Order_Details') {
				$query = "SELECT * FROM [Order Details] WHERE OrderID = $sourceEntityInstance->OrderID";
				$stmt = sqlsrv_query($this->_connectionHandle, $query);
				if ($stmt === false) {
					$errorAsString = self::_getSQLSRVError();
					throw ODataException::createInternalServerError($errorAsString);
				}

				$result = $this->_serializeOrderDetails($stmt);
			} else {
				die('Order does not have navigation porperty with name: ' . $navigationPropName);
			}
		}

		return empty($result) ? null : $result[0];

	}


	/**
	 * Gets a related entity instance from an entity set identified by a key
	 * IE: http://host/EntitySet(1L)/NavigationPropertyToCollection(33)
	 *
	 * @param ResourceSet $sourceResourceSet The entity set containing the source entity
	 * @param $sourceEntityInstance The source entity instance.
	 * @param ResourceSet $targetResourceSet The entity set containing the entity to fetch
	 * @param ResourceProperty $targetProperty The metadata of the target property.
	 * @param KeyDescriptor $keyDescriptor The key identifying the entity to fetch
	 *
	 * @return object|null Returns entity instance if found else null
	 */
	public function getResourceFromRelatedResourceSet(
		ResourceSet $sourceResourceSet,
		$sourceEntityInstance,
		ResourceSet $targetResourceSet,
		ResourceProperty $targetProperty,
		KeyDescriptor $keyDescriptor
	) {
		// TODO: Implement getResourceFromRelatedResourceSet() method.
	}

	/**
	 * Get related resource for a resource
	 * IE: http://host/EntitySet(1L)/NavigationPropertyToSingleEntity
	 * http://host/EntitySet?$expand=NavigationPropertyToSingleEntity
	 *
	 * @param ResourceSet $sourceResourceSet The entity set containing the source entity
	 * @param $sourceEntityInstance The source entity instance.
	 * @param ResourceSet $targetResourceSet The entity set containing the entity pointed to by the navigation property
	 * @param ResourceProperty $targetProperty The navigation property to fetch
	 *
	 * @return object|null The related resource if found else null
	 */
	public function getRelatedResourceReference(
		ResourceSet $sourceResourceSet,
		$sourceEntityInstance,
		ResourceSet $targetResourceSet,
		ResourceProperty $targetProperty
	) {
		// TODO: Implement getRelatedResourceReference() method.
	}
}