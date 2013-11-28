<?php

namespace POData\Services\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Registry;
use POData\Providers\Metadata\ResourceStreamInfo;
use POData\Providers\Metadata\ResourceAssociationSetEnd;
use POData\Providers\Metadata\ResourceAssociationSet;
use POData\Common\NotImplementedException;
use POData\Providers\Metadata\Type\EdmPrimitiveType;
use POData\Providers\Metadata\ResourceSet;
use POData\Providers\Metadata\ResourcePropertyKind;
use POData\Providers\Metadata\ResourceProperty;
use POData\Providers\Metadata\ResourceTypeKind;
use POData\Providers\Metadata\ResourceType;
use POData\Common\InvalidOperationException;
use POData\Providers\Metadata\IMetadataProvider;
use POData\Providers\Metadata\SimpleMetadataProvider;

class DoctrineMetadata
{
    /**
     * create metadata
     * 
     * @throws InvalidOperationException
     * 
     * @return DoctrineMetadata
     */
    public static function create(Registry $doctrine)
    {
        $metadata = new DoctrineMetadataProvider('Apollo', 'Viva', $doctrine);

		$metadata->addEntity('Viva\ApolloBundle\Entity\Member', 'Apollo');
		$metadata->addEntity('Viva\ApolloBundle\Entity\Address', 'Apollo');
		$metadata->addEntity('Viva\ApolloBundle\Entity\Tag', 'Apollo');
		//$metadata->addEntity('Viva\ApolloBundle\Entity\Contact', 'Apollo');
//		$metadata->addEntity('Viva\ApolloBundle\Entity\Contact_Email', 'Apollo');
//		$metadata->addEntity('Viva\ApolloBundle\Entity\Contact_Mobile', 'Apollo');
//		$metadata->addEntity('Viva\ApolloBundle\Entity\Contact_Telephone', 'Apollo');

		$metadata->mapAssociations();

		return $metadata;
    }
}
