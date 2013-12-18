<?php

namespace POData\Services\Doctrine\Metadata;
use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("PROPERTY")
 */
class Column extends Annotation {
	public $type    = null;
	public $visible = true;
}