<?php

namespace Booking\Service\Diagnostic;

/**
 * Holds all registered diagnostic checks and selects subsets by key/category.
 */
class CheckRegistry
{

    /** @var DiagnosticCheckInterface[] */
    protected $checks = array();

    /**
     * @param DiagnosticCheckInterface[] $checks
     */
    public function __construct(array $checks = array())
    {
        foreach ($checks as $check) {
            $this->add($check);
        }
    }

    /**
     * @param DiagnosticCheckInterface $check
     */
    public function add(DiagnosticCheckInterface $check)
    {
        $this->checks[$check->getKey()] = $check;
    }

    /**
     * @return DiagnosticCheckInterface[] keyed by check key
     */
    public function all()
    {
        return $this->checks;
    }

    /**
     * Selects checks by a list of tokens, each matching either a full check
     * key ('occupancy.double-booking') or a category ('occupancy'). An empty
     * selection returns all checks.
     *
     * @param array $tokens
     * @return DiagnosticCheckInterface[]
     */
    public function select(array $tokens)
    {
        $tokens = array_filter(array_map('trim', $tokens));

        if (! $tokens) {
            return $this->checks;
        }

        $selected = array();

        foreach ($this->checks as $key => $check) {
            foreach ($tokens as $token) {
                if ($key === $token || $check->getCategory() === $token) {
                    $selected[$key] = $check;
                    break;
                }
            }
        }

        return $selected;
    }

}
