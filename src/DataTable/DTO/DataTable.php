<?php

namespace Umbrella\CoreBundle\DataTable\DTO;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Umbrella\CoreBundle\DataTable\AdapterException;

class DataTable
{
    protected Toolbar $toolbar;

    /**
     * @var Column[]
     */
    protected array $columns;

    protected Adapter $adapter;

    protected RowModifier $rowModifier;

    protected array $options;

    protected DataTableState $state;

    protected ?DataTableResponse $response = null;

    protected bool $callback = false;

    /**
     * DataTable constructor.
     */
    public function __construct(
        Toolbar $toolbar,
        array $columns,
        Adapter $adapter,
        RowModifier $rowModifier,
        array $options
    ) {
        $this->toolbar = $toolbar;
        $this->columns = $columns;
        $this->adapter = $adapter;
        $this->rowModifier = $rowModifier->setIsTree($options['tree']);
        $this->options = $options;

        $this->state = new DataTableState($this);
    }

    public function getId(): string
    {
        return $this->options['id'];
    }

    public function getToolbar(): Toolbar
    {
        return $this->toolbar;
    }

    /**
     * @return array|Column[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumn(string $name): Column
    {
        return $this->columns[$name];
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name)
    {
        return $this->options[$name];
    }

    public function getState(): DataTableState
    {
        return $this->state;
    }

    public function handleRequest(Request $httpRequest): self
    {
        $this->callback = false;
        $this->response = null;

        // Invalid method
        if (!$httpRequest->isMethod($this->options['method'])) {
            return $this;
        }

        $data = 'POST' === $this->options['method']
            ? $httpRequest->request->all()
            : $httpRequest->query->all();

        // Invalid or missing datatable id
        if (!isset($data['_dtid']) || $data['_dtid'] != $this->getId()) {
            return $this;
        }

        // Valid callback => update state
        $this->callback = true;
        $this->state = new DataTableState($this);
        $this->state->applyParameters($data);

        $this->toolbar->handleRequest($httpRequest);
        $this->state->setFormData($this->toolbar->getFormData());

        return $this;
    }

    public function handleParameters(array $parameters): self
    {
        $this->response = null;

        $this->state = new DataTableState($this);
        $this->state->applyParameters($parameters);
        $this->toolbar->submitData($parameters);
        $this->state->setFormData($this->toolbar->getFormData());

        return $this;
    }

    public function isCallback(): bool
    {
        return $this->callback;
    }

    public function getCallbackResponse(): DataTableResponse
    {
        if (!$this->isCallback()) {
            throw new \RuntimeException('Unable to get callback response, request is not valid');
        }

        if (null !== $this->response) {
            return $this->response;
        }

        try {
            $result = $this->getAdapterResult();
        } catch (AdapterException $exception) {
            return DataTableResponse::createError($exception->getMessage());
        }

        // Create Row Views
        $rowViews = [];
        foreach ($result->getData() as $object) {
            $view = new RowView();
            foreach ($this->columns as $column) {
                $view->data[] = $column->render($object);
            }
            $this->rowModifier->modify($view, $object);
            $rowViews[] = $view;
        }

        return DataTableResponse::createSuccess($rowViews, $result->getCount(), $this->state->getDraw());
    }

    // Adapter helper

    public function getAdapterResult(): DataTableResult
    {
        return $this->adapter->getResult($this->state);
    }

    public function getAdapterQueryBuilder(): QueryBuilder
    {
        return $this->adapter->getQueryBuilder($this->state);
    }
}
