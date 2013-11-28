<?php

namespace POData\Services\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Annotations\Annotation\Target;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\DocParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use POData\Common\InvalidOperationException;
use POData\Common\Messages;
use POData\Providers\Metadata\IMetadataProvider;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourceType;
use POData\Providers\Metadata\ResourceTypeKind;
use POData\Providers\Metadata\SimpleMetadataProvider;
use POData\Providers\Metadata\Type\EdmPrimitiveType;
use Symfony\Component\DependencyInjection\Container;

class DoctrineMetadataProvider extends SimpleMetadataProvider {
	protected $entities = array();
	private $doctrine;


	/**
	 *
	 * @param string $containerName container name for the datasource.
	 * @param string $namespaceName namespace for the datasource.
	 *
	 */
	public function __construct($containerName, $namespaceName, Registry $doctrine)
	{
		parent::__construct($containerName, $namespaceName);

		$this->doctrine      = $doctrine;
	}

	public function addEntity($entity, $namespace) {
		// Get the meta data
		$cmf = $this->doctrine->getManager()->getMetadataFactory();
		$meta = $cmf->getMetadataFor($entity);
		$name = $this->removeNamespace($meta->getName());

		// Is it a polymorphic entity?
		if (!empty($meta->subClasses)) {
			$type = $this->addAbstractType(new \ReflectionClass($entity), $name, $namespace);

			foreach ($meta->subClasses as $subclass) {
				$subName = $this->removeNamespace($subclass);
				$this->addComplexType(new \ReflectionClass($subclass), $subName, $namespace);
			}
		}
		else {
			$type = $this->addEntityType(new \ReflectionClass($entity), $name, $namespace);
		}

		$this->addEntityProperties($entity, $type, $meta);
		$this->addResourceSet($name, $type);

		return $this;
	}

	/**
	 * Add a complex type
	 *
	 * @param \ReflectionClass $refClass         reflection class of the complex entity type
	 * @param string          $name             name of the entity
	 * @param string          $namespace        namespace of the data source.
	 * @param ResourceType    $baseResourceType base resource type
	 *
	 * @return ResourceType
	 *
	 * @throws InvalidOperationException when the name is already in use
	 */
	public function addAbstractType(\ReflectionClass $refClass, $name, $namespace = null, $baseResourceType = null)
	{
		if (array_key_exists($name, $this->resourceTypes)) {
			throw new InvalidOperationException('Type with same name already added');
		}

		$complexType = new DoctrineResourceType($refClass, ResourceTypeKind::COMPLEX, $name, $namespace, $baseResourceType, true);
		$this->resourceTypes[$name] = $complexType;
		return $complexType;
	}


	/**
	 * Add an entity type
	 *
	 * @param \ReflectionClass $refClass  reflection class of the entity
	 * @param string $name name of the entity
	 * @param string $namespace namespace of the data source
	 *
	 * @return ResourceType
	 *
	 * @throws InvalidOperationException when the name is already in use
	 */
	public function addEntityType(\ReflectionClass $refClass, $name, $namespace = null)
	{
		if (array_key_exists($name, $this->resourceTypes)) {
			throw new InvalidOperationException('Type with same name already added');
		}

		$entityType = new DoctrineResourceType($refClass, ResourceTypeKind::ENTITY, $name, $namespace);
		$this->resourceTypes[$name] = $entityType;
		return $entityType;
	}

	/**
	 * Add a complex type
	 *
	 * @param \ReflectionClass $refClass         reflection class of the complex entity type
	 * @param string          $name             name of the entity
	 * @param string          $namespace        namespace of the data source.
	 * @param ResourceType    $baseResourceType base resource type
	 *
	 * @return ResourceType
	 *
	 * @throws InvalidOperationException when the name is already in use
	 */
	public function addComplexType(\ReflectionClass $refClass, $name, $namespace = null, $baseResourceType = null)
	{
		if (array_key_exists($name, $this->resourceTypes)) {
			throw new InvalidOperationException('Type with same name already added');
		}

		$complexType = new DoctrineResourceType($refClass, ResourceTypeKind::COMPLEX, $name, $namespace, $baseResourceType);
		$this->resourceTypes[$name] = $complexType;
		return $complexType;
	}

	protected function removeNamespace($class) {
		// Work out the name
		$name = strrchr($class, '\\');

		if (!$name) {
			return $class;
		}
		else {
			return substr($name, 1);
		}
	}

	protected function addEntityProperties($entity, ResourceType $type, ClassMetadata $meta) {
		$docParser = new DocParser();

		// Add the fields
		foreach ($meta->fieldMappings as $fieldMapping) {
			$typeCode = $this->getResourceTypeCode($fieldMapping['type']);

			$method = 'get'. Container::camelize($fieldMapping['fieldName']);
			$property = new \ReflectionMethod($entity, $method);

			$class = $property->getDeclaringClass();
			$context = 'property ' . $class->getName() . "::\$" . $property->getName();
			$docParser->setTarget(Target::TARGET_PROPERTY);
			$docParser->setIgnoreNotImportedAnnotations(true);

			$classAnnotations = $docParser->parse($property->getDocComment(), $context);


			var_dump($classAnnotations);

			if (!empty($fieldMapping['id'])) {
				$this->addKeyProperty($type, $fieldMapping['fieldName'], $typeCode);
			}
			else {
				$this->addPrimitiveProperty($type, $fieldMapping['fieldName'], $typeCode);
			}
		}
	}
	protected function addEntityAssocations(ResourceSet $set, ClassMetadata $meta) {
		$type = $set->getResourceType();
		// Add the associations
		foreach ($meta->getAssociationMappings() as $name => $association) {
			$target = $this->removeNamespace($association['targetEntity']);
			$target_set = $this->resolveResourceSet($target);
			$target_type = $this->resolveResourceType($target);

			if (!$target_set) {
				continue;
			}

			//var_dump($association);
			/*
			 *
        //Register the assoications (navigations)
        //Customers (1) <==> Orders (0-*)
        $metadata->addResourceSetReferenceProperty($customersEntityType, 'Orders', $ordersResourceSet);
        $metadata->addResourceReferenceProperty($orderEntityType, 'Customer', $customersResourceSet);

        //Register the assoications (navigations)
        //Orders (1) <==> Orders_Details (0-*)
        $metadata->addResourceReferenceProperty($orderDetailsEntityType, 'Order', $ordersResourceSet);
        $metadata->addResourceSetReferenceProperty($orderEntityType, 'Order_Details', $orderDetialsResourceSet);
			 * */

			switch ($association['type']) {
				case ClassMetadataInfo::ONE_TO_ONE:
					$this->addResourceReferenceProperty($type, $association['fieldName'], $target_set);
				break;
				case ClassMetadataInfo::ONE_TO_MANY:
					$this->addResourceReferenceProperty($target_type, $association['mappedBy'], $set);
					$this->addResourceSetReferenceProperty($type, $association['fieldName'], $target_set);
				break;
			}

			$this->resolveResourceType($name);
		};
	}

	/**
	 * Maps a Doctrine type to a primitive type
	 *
	 * @param $type string Doctrine type
	 * @return int The Edm type constant
	 * @throws \InvalidArgumentException
	 */
	protected function getResourceTypeCode($type) {
		switch($type) {
			case Type::TARRAY:
			case Type::SIMPLE_ARRAY:
			case Type::JSON_ARRAY:
			case Type::OBJECT:
			case Type::STRING:
			case Type::TEXT:
				return EdmPrimitiveType::STRING;
			case Type::BLOB:
				return EdmPrimitiveType::BINARY;
			case Type::GUID:
				return EdmPrimitiveType::GUID;
			case Type::DECIMAL:
				return EdmPrimitiveType::DECIMAL;
			case Type::FLOAT:
				return EdmPrimitiveType::SINGLE;
			case Type::SMALLINT:
				return EdmPrimitiveType::INT16;
			case Type::INTEGER:
				return EdmPrimitiveType::INT32;
			case Type::BIGINT:
				return EdmPrimitiveType::INT64;
			case Type::BOOLEAN:
				return EdmPrimitiveType::BOOLEAN;
			case Type::DATETIME:
			case Type::DATETIMETZ:
				// TODO: Implement Edm.time primitive type
			case Type::DATE:
			case Type::TIME:
				return EdmPrimitiveType::DATETIME;
			default:
				// TODO: Change me to use my own message
				throw new \InvalidArgumentException(
					Messages::commonNotValidPrimitiveEDMType(
						'$typeCode', 'getPrimitiveResourceType'
					)
				);
		}
	}

	public function mapAssociations() {
		$cmf = $this->doctrine->getManager()->getMetadataFactory();

		/* @var $set ResourceSet */
		foreach ($this->resourceSets as $set) {
			$type = $set->getResourceType();
			$meta = $cmf->getMetadataFor($type->getInstanceType()->getName());

			$this->addEntityAssocations($set, $meta);
		}
	}
}