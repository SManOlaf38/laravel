<?php namespace Laravel;

/**
 * The view factory class is responsible for the instantiation of Views. It is typically
 * access through the application instance from a route or controller, and is managed
 * as a singleton by the application IoC container.
 */
class View_Factory {

	/**
	 * The view composer instance.
	 *
	 * @var View_Composer
	 */
	protected $composer;

	/**
	 * The directory containing the views.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Create a new view factory instance.
	 *
	 * @param  View_Composer  $composer
	 * @param  string         $path
	 * @return void
	 */
	public function __construct(View_Composer $composer, $path)
	{
		$this->composer = $composer;
		$this->path = $path;
	}

	/**
	 * Create a new view instance.
	 *
	 * The name of the view given to this method should correspond to a view
	 * within your application views directory. Dots or slashes may used to
	 * reference views within sub-directories.
	 *
	 * <code>
	 *		// Create a new view instance
	 *		$view = View::make('home.index');
	 *
	 *		// Create a new view instance with bound data
	 *		$view = View::make('home.index', array('name' => 'Fred'));
	 * </code>
	 *
	 * @param  string  $view
	 * @param  array   $data
	 * @return View
	 */
	public function make($view, $data = array())
	{
		return new View($this, $this->composer, $view, $data, $this->path($view));
	}

	/**
	 * Create a new view instance from a view name.
	 *
	 * View names are defined in the application composers file.
	 *
	 * <code>
	 *		// Create a new named view instance
	 *		$view = View::of('layout');
	 *
	 *		// Create a new named view instance with bound data
	 *		$view = View::of('layout', array('name' => 'Fred'));
	 * </code>
	 *
	 * @param  string  $name
	 * @param  array   $data
	 * @return View
	 */
	protected function of($name, $data = array())
	{
		if ( ! is_null($view = $this->composer->name($name)))
		{
			return $this->make($view, $data);
		}

		throw new \Exception("Named view [$name] is not defined.");
	}

	/**
	 * Get the path to a given view on disk.
	 *
	 * @param  string  $view
	 * @return string
	 */
	protected function path($view)
	{
		$view = str_replace('.', '/', $view);

		if (file_exists($path = $this->path.$view.'.blade'.EXT))
		{
			return $path;
		}
		elseif (file_exists($path = $this->path.$view.EXT))
		{
			return $path;
		}

		throw new \Exception('View ['.$view.'] does not exist.');
	}

	/**
	 * Magic Method for handling the dynamic creation of named views.
	 *
	 * <code>
	 *		// Create an instance of the "layout" named view
	 *		$view = View::of_layout();
	 *
	 *		// Create an instance of the "layout" named view with bound data
	 *		$view = View::of_layout(array('name' => 'Fred'));
	 * </code>
	 */
	public function __call($method, $parameters)
	{
		if (strpos($method, 'of_') === 0)
		{
			return $this->of(substr($method, 3), Arr::get($parameters, 0, array()));
		}
	}

}

/**
 * The view composer class is responsible for calling the composer on a view and
 * searching through the view composers for a given view name. It is injected
 * into the View_Factory and View instances themselves, and is managed as a singleton
 * by the application IoC container.
 */
class View_Composer {

	/**
	 * The view composers.
	 *
	 * @var array
	 */
	protected $composers;

	/**
	 * Create a new view composer instance.
	 *
	 * @param  array      $composers
	 * @return void
	 */
	public function __construct($composers)
	{
		$this->composers = $composers;
	}

	/**
	 * Find the key for a view by name.
	 *
	 * @param  string  $name
	 * @return string
	 */
	public function name($name)
	{
		foreach ($this->composers as $key => $value)
		{
			if ($name === $value or (isset($value['name']) and $name === $value['name'])) { return $key; }
		}
	}

	/**
	 * Call the composer for the view instance.
	 *
	 * @param  View  $view
	 * @return void
	 */
	public function compose(View $view)
	{
		if (isset($this->composers['shared'])) call_user_func($this->composers['shared'], $view);

		if (isset($this->composers[$view->view]))
		{
			foreach ((array) $this->composers[$view->view] as $key => $value)
			{
				if ($value instanceof \Closure) return call_user_func($value, $view);
			}
		}
	}

}

/**
 * The view class is returned by the View Factory "make" method, and is the primary
 * class for working with individual views. It provides methods for binding data to
 * views as well as evaluating and rendering their contents.
 */
class View {

	/**
	 * The name of the view.
	 *
	 * @var string
	 */
	public $view;

	/**
	 * The view data.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * The path to the view on disk.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * The view composer instance.
	 *
	 * @var View_Composer
	 */
	protected $composer;

	/**
	 * The view factory instance, which is used to create sub-views.
	 *
	 * @var View_Factory
	 */
	protected $factory;

	/**
	 * Create a new view instance.
	 *
	 * @param  View_Factory   $factory
	 * @param  View_Composer  $composer
	 * @param  string         $view
	 * @param  array          $data
	 * @param  string         $path
	 * @return void
	 */
	public function __construct(View_Factory $factory, View_Composer $composer, $view, $data, $path)
	{
		$this->view = $view;
		$this->data = $data;
		$this->path = $path;
		$this->factory = $factory;
		$this->composer = $composer;
	}

	/**
	 * Get the evaluated string content of the view.
	 *
	 * If the view has a composer, it will be executed. All sub-views and responses will
	 * also be evaluated and converted to their string values.
	 *
	 * @return string
	 */
	public function render()
	{
		$this->composer->compose($this);

		foreach ($this->data as &$data) 
		{
			if ($data instanceof View or $data instanceof Response) $data = $data->render();
		}

		ob_start() and extract($this->data, EXTR_SKIP);

		$content = ($this->bladed()) ? Blade::parse($this->path) : file_get_contents($this->path);

		eval('?>'.$content);

		return ob_get_clean();
	}

	/**
	 * Determine if the view is using the blade view engine.
	 *
	 * @return bool
	 */
	protected function bladed()
	{
		return (strpos($this->path, '.blade'.EXT) !== false);
	}

	/**
	 * Add a view instance to the view data.
	 *
	 * <code>
	 *		// Bind a partial view to the view data
	 *		$view->partial('footer', 'partials/footer');
	 *
	 *		// Bind a partial view to the view data with it's own bound data
	 *		$view->partial('footer', 'partials/footer', array('name' => 'Fred'));
	 * </code>
	 *
	 * @param  string  $key
	 * @param  string  $view
	 * @param  array   $data
	 * @return View
	 */
	public function partial($key, $view, $data = array())
	{
		return $this->with($key, $this->factory->make($view, $data));
	}

	/**
	 * Add a key / value pair to the view data.
	 *
	 * Bound data will be available to the view as variables.
	 *
	 * <code>
	 *		// Bind a piece of data to a view instance
	 *		$view->with('name', 'Fred');
	 * </code>
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return View
	 */
	public function with($key, $value)
	{
		$this->data[$key] = $value;
		return $this;
	}

	/**
	 * Magic Method for getting items from the view data.
	 */
	public function __get($key)
	{
		return $this->data[$key];
	}

	/**
	 * Magic Method for setting items in the view data.
	 */
	public function __set($key, $value)
	{
		$this->with($key, $value);
	}

	/**
	 * Magic Method for determining if an item is in the view data.
	 */
	public function __isset($key)
	{
		return array_key_exists($key, $this->data);
	}

	/**
	 * Magic Method for removing an item from the view data.
	 */
	public function __unset($key)
	{
		unset($this->data[$key]);
	}

}