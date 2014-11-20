<?php namespace Anomaly\Streams\Platform\Ui\Table;

use Anomaly\Streams\Platform\Model\EloquentModel;
use Anomaly\Streams\Platform\Traits\DispatchableTrait;
use Anomaly\Streams\Platform\Ui\Table\Contract\TableRepositoryInterface;
use Anomaly\Streams\Platform\Ui\Table\Event\QueryingEvent;
use Illuminate\Pagination\Paginator;

/**
 * Class TableRepository
 *
 * This class provides entry data for the Table class
 * in a way that can be replicated with another provider
 * like a 3rd party API service.
 *
 * @link          http://anomaly.is/streams-platform
 * @author        AnomalyLabs, Inc. <hello@anomaly.is>
 * @author        Ryan Thompson <ryan@anomaly.is>
 * @package       Anomaly\Streams\Platform\Ui\Table
 */
class TableRepository implements TableRepositoryInterface
{

    use DispatchableTrait;

    /**
     * The table UI object.
     *
     * @var Table
     */
    protected $table;

    /**
     * The model object if any.
     *
     * @var \Anomaly\Streams\Platform\Model\EloquentModel
     */
    protected $model;

    /**
     * The request object.
     *
     * @var mixed
     */
    protected $request;

    /**
     * Create a new TableRepository instance.
     *
     * @param Table         $table
     * @param EloquentModel $model
     */
    function __construct(Table $table)
    {
        $this->table = $table;

        $this->request = app('request');
    }

    /**
     * Return a Collection of EntryInterface objects
     * based on the configuration in the UI object.
     *
     * Any pagination / query logic needs to be either
     * done here or trigger / fired from here in
     * order to keep a cohesive request.
     *
     * @return mixed
     */
    public function get()
    {
        $model = $this->table->getModel();

        // Prevent joins from overriding values.
        $query = $model->select($model->getTable() . '.*');

        // Eager load desired relations.
        $query = $query->with($this->table->getEager());

        /**
         * This hook is used for views / actions
         * and any other query hooks the developer
         * want's to implement.
         */

        // Set the query so we can modify it.
        $this->table->setQuery($query);

        $this->dispatch(new QueryingEvent($this->table));

        // This was just modified above (potentially).
        $query = $this->table->getQuery();

        /**
         * Save the total of the basic query
         * after hooks but before paging.
         */
        $total = $query->count();

        /**
         * Make sure the page exists
         * before going any further.
         */
        $this->assurePageExists($total);

        /**
         * Return only the page's entries based
         * on configured limit and the current page.
         */
        $limit  = $this->table->getLimit();
        $offset = $limit * ($this->request->get('page', 1) - 1);

        $query = $query->take($limit)->offset($offset);

        /**
         * Order the query results.
         */
        foreach ($this->table->getOrderBy() as $column => $direction) {

            $query = $query->orderBy($column, $direction);
        }

        /**
         * Finally get the resulting entries.
         */
        $entries = $query->get();

        /**
         * Build a paginator to use later.
         */
        $this->makePagination($total);

        return $entries;
    }

    /**
     * Make the pagination and set it on the
     * table UI object to be used later.
     *
     * @param $total
     */
    protected function makePagination($total)
    {
        $request = app('request');

        $items   = [];
        $page    = $request->get('page');
        $perPage = $this->table->getLimit();
        $path    = '/' . $request->path();

        $paginator = new Paginator($items, $total, $perPage, $page, compact('path'));

        $this->table->setPaginator($paginator);
    }

    /**
     * If the request attempts to access a set
     * of entries that is outside of the total
     * available set then try and redirect to an
     * applicable page until on page 1.
     *
     * This is really helpful when manipulating an
     * entire page of entries using a table action
     * like delete or move.
     *
     * @param $total
     */
    protected function assurePageExists($total)
    {
        $limit  = $this->table->getLimit();
        $page   = $this->request->get('page', 1);
        $offset = $limit * ($page - 1);

        if ($total < $offset and $page > 1) {

            $url = str_replace('page=' . $page, 'page=' . ($page - 1), app('request')->fullUrl());

            header('Location: ' . $url);
        }
    }
}
 