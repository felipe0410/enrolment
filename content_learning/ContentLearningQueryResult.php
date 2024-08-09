<?php

namespace go1\enrolment\content_learning;

class ContentLearningQueryResult
{
    private $getItemFn;
    private $getCountFn;
    private $getFacetCountFn;

    public function __construct(callable $getItemFn, callable $getCountFn, callable $getFacetCountFn)
    {
        $this->getItemFn = $getItemFn;
        $this->getCountFn = $getCountFn;
        $this->getFacetCountFn = $getFacetCountFn;
    }

    public function getItems(): array
    {
        return call_user_func($this->getItemFn);
    }

    public function getCount(): int
    {
        return call_user_func($this->getCountFn);
    }

    public function getFacetCount(): array
    {
        return call_user_func($this->getFacetCountFn);
    }
}
