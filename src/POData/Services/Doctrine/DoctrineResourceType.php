<?php

namespace POData\Services\Doctrine;

use POData\Providers\Metadata\ResourceType;
use Symfony\Component\DependencyInjection\Container;

class DoctrineResourceType extends ResourceType {

	public function setPropertyValue($entity, $property, $value) {
		$method = 'set'.Container::camelize($property);

		if (method_exists($entity, $method)) {
			return $entity->$method($value);
		}

		$reflect = new \ReflectionProperty($entity, $property);
		$reflect->setAccessible(true);
		$reflect->setValue($entity, $value);

		return $this;
	}

	public function getPropertyValue($entity, $property) {
		$method = 'get'.Container::camelize($property);

		if (method_exists($entity, $method)) {
			return $entity->$method();
		}

		// Issue #88 - is this too slow?
		$reflect = new \ReflectionProperty($entity, $property);
		$reflect->setAccessible(true);

		return $reflect->getValue($entity);
	}
} 