<?php

namespace Booking\Service\Diagnostic;

/**
 * Base class reducing boilerplate for concrete checks.
 */
abstract class AbstractCheck implements DiagnosticCheckInterface
{

    /**
     * Category defaults to the part before the first dot in the key.
     *
     * @return string
     */
    public function getCategory()
    {
        $key = $this->getKey();
        $pos = strpos($key, '.');

        return $pos === false ? $key : substr($key, 0, $pos);
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return '';
    }

    /**
     * @return boolean
     */
    public function needsDateRange()
    {
        return false;
    }

    /**
     * Convenience factory for a Finding tied to this check.
     *
     * @param string $severity
     * @param string $title
     * @param array $options
     * @return Finding
     */
    protected function finding($severity, $title, array $options = array())
    {
        return new Finding($this->getKey(), $this->getCategory(), $severity, $title, $options);
    }

}
