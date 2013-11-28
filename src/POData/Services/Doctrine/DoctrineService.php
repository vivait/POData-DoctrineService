<?php

namespace POData\Services\Doctrine;

use DoctrineStreamProvider;
use POData\Configuration\EntitySetRights;
use POData\Configuration\ProtocolVersion;
use POData\Configuration\ServiceConfiguration;
use POData\BaseService;
use POData\Providers\Metadata\SimpleMetadataProvider;

class DoctrineService extends BaseService
{
    private $_doctrineMetadata;
    private $_doctrineQueryProvider;
    private $_doctrine;

	public function __construct($doctrine) {
		$this->_doctrine = $doctrine;
	}

    /**
     * This method is called only once to initialize service-wide policies
     * 
     * @param ServiceConfiguration $config Data service configuration object
     * 
     * @return void
     */
    public function initialize(ServiceConfiguration $config)
    {
        $config->setEntitySetPageSize('*', 5);
        $config->setEntitySetAccessRule('*', EntitySetRights::ALL);
        $config->setAcceptCountRequests(true);
        $config->setAcceptProjectionRequests(true);
        $config->setMaxDataServiceVersion(ProtocolVersion::V3());
    }

	/**
	 * @return \POData\Providers\Query\IQueryProvider
	 */
	public function getQueryProvider() {
		if (is_null($this->_doctrineQueryProvider)) {
			$this->_doctrineQueryProvider = new DoctrineQueryProvider($this->_doctrine);
		}
		return $this->_doctrineQueryProvider;
	}

	/**
	 * @return \POData\Providers\Metadata\IMetadataProvider
	 */
	public function getMetadataProvider() {
		return DoctrineMetadata::create($this->_doctrine);
	}

	/**
	 * @return \POData\Providers\Stream\IStreamProvider
	 */
	public function getStreamProviderX() {
		return new DoctrineStreamProvider();
	}
}