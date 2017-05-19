<?php

namespace Carbon_Fields\Field;

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Pimple\Container as PimpleContainer;
use Carbon_Fields\Datastore\Datastore_Interface;
use Carbon_Fields\Datastore\Datastore_Holder_Interface;
use Carbon_Fields\Value_Set\Value_Set;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Base field class.
 * Defines the key container methods and their default implementations.
 * Implements factory design pattern.
 */
class Field implements Datastore_Holder_Interface {
	
	/**
	 * Array of field class names that have had their activation method called
	 *
	 * @var array<string>
	 */
	protected static $activated_field_types = array();

	/**
	 * Globally unique field identificator. Generated randomly
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Stores the initial <kbd>$type</kbd> variable passed to the <code>factory()</code> method
	 *
	 * @see factory
	 * @var string
	 */
	public $type;

	/**
	 * Array of ancestor field names
	 *
	 * @var array
	 */
	protected $hierarchy = array();

	/**
	 * Array of complex entry ids
	 *
	 * @var array
	 */
	protected $hierarchy_index = array();

	/**
	 * Field value
	 *
	 * @var Value_Set
	 */
	protected $value_set;

	/**
	 * Default field value
	 *
	 * @var mixed
	 */
	protected $default_value = '';

	/**
	 * Sanitized field name used as input name attribute during field render
	 *
	 * @see factory()
	 * @see set_name()
	 * @var string
	 */
	protected $name;

	/**
	 * Field name prefix
	 *
	 * @see set_name()
	 * @var string
	 */
	protected $name_prefix = '_';

	/**
	 * The base field name which is used in the container.
	 *
	 * @see set_base_name()
	 * @var string
	 */
	protected $base_name;

	/**
	 * Field name used as label during field render
	 *
	 * @see factory()
	 * @see set_label()
	 * @var string
	 */
	protected $label;

	/**
	 * Additional text containing information and guidance for the user
	 *
	 * @see help_text()
	 * @var string
	 */
	protected $help_text;

	/**
	 * Field DataStore instance to which save, load and delete calls are delegated
	 *
	 * @see set_datastore()
	 * @see get_datastore()
	 * @var Datastore_Interface
	 */
	protected $datastore;

	/**
	 * Flag whether the datastore is the default one or replaced with a custom one
	 *
	 * @see set_datastore()
	 * @see get_datastore()
	 * @var boolean
	 */
	protected $has_default_datastore = true;

	/**
	 * The type of the container this field is in
	 *
	 * @see get_context()
	 * @var string
	 */
	protected $context;

	/**
	 * Whether or not this value should be auto loaded. Applicable to theme options only.
	 *
	 * @see set_autoload()
	 * @var bool
	 */
	protected $autoload = false;

	/**
	 * Whether or not this field will be initialized when the field is in the viewport (visible).
	 *
	 * @see set_lazyload()
	 * @var bool
	 */
	protected $lazyload = false;

	/**
	 * Key-value array of attribtues and their values
	 * 
	 * @var array
	 */
	protected $attributes = array(
		'type' => 'text',
	);

	/**
	 * Array of attributes the user is allowed to change
	 * 
	 * @var array<string>
	 */
	protected $allowed_attributes = array( 'max', 'maxlength', 'min', 'pattern', 'placeholder', 'readonly', 'step', 'type' );

	/**
	 * The width of the field.
	 *
	 * @see set_width()
	 * @var int
	 */
	protected $width = 0;

	/**
	 * Custom CSS classes.
	 *
	 * @see set_classes()
	 * @var array
	 */
	protected $classes = array();

	/**
	 * Whether or not this field is required.
	 *
	 * @see set_required()
	 * @var bool
	 */
	protected $required = false;

	/**
	 * Stores the field conditional logic rules.
	 *
	 * @var array
	 */
	protected $conditional_logic = array();

	/**
	 * Whether the field should be included in the response of the requests to the REST API
	 *
	 * @see  set_visible_in_rest_api
	 * @see  get_visible_in_rest_api
	 * @var boolean
	 */
	protected $visible_in_rest_api = false;

	/**
	 * Clone the Value_Set object as well
	 *
	 * @var array
	 */
	public function __clone() {
		$this->set_value_set( clone $this->get_value_set() );
	}

	/**
	 * Create a new field of type $raw_type and name $name and label $label.
	 *
	 * @param string $raw_type
	 * @param string $name lower case and underscore-delimited
	 * @param string $label (optional) Automatically generated from $name if not present
	 * @return Field
	 */
	public static function factory( $raw_type, $name, $label = null ) {
		$type = Helper::normalize_type( $raw_type );

		if ( Carbon_Fields::has( $type, 'fields' ) ) {
			return Carbon_Fields::resolve_with_arguments( $type, array(
				'type' => $type,
				'name' => $name,
				'label' => $label,
			), 'fields' );
		}

		// Fallback to class name-based resolution
		$class = Helper::type_to_class( $type, __NAMESPACE__, '_Field' );
		if ( ! class_exists( $class ) ) {
			Incorrect_Syntax_Exception::raise( 'Unknown field "' . $raw_type . '".' );
			$class = __NAMESPACE__ . '\\Broken_Field';
		}

		$field = new $class( $type, $name, $label );
		return $field;
	}

	/**
	 * An alias of factory().
	 *
	 * @see Field::factory()
	 * @return Field
	 */
	public static function make( $type, $name, $label = null ) {
		return static::factory( $type, $name, $label );
	}

	/**
	 * Create a field from a certain type with the specified label.
	 * 
	 * @param string $type  Field type
	 * @param string $name  Field name
	 * @param string $label Field label
	 */
	public function __construct( $type, $name, $label ) {
		Carbon_Fields::verify_boot();
		
		$this->type = $type;
		$this->set_base_name( $name );
		$this->set_name( $name );
		$this->set_label( $label );

		// Pick random ID
		$random_string = md5( mt_rand() . $this->get_name() . $this->get_label() );
		$random_string = substr( $random_string, 0, 5 ); // 5 chars should be enough
		$this->id = 'carbon-' . $random_string;

		$this->init();
	}

	/**
	 * Returns the type of the field based on the class.
	 * The class is stripped by the "CarbonFields" prefix.
	 * Also the "Field" suffix is removed.
	 * Then underscores and backslashes are removed.
	 *
	 * @return string
	 */
	public function get_type() {
		return Helper::class_to_type( get_class( $this ), '_Field' );
	}

	/**
	 * Activate the field once the container is attached.
	 */
	public function activate() {
		$this->admin_init();

		add_action( 'admin_print_footer_scripts', array( get_class(), 'admin_hook_scripts' ), 5 );
		add_action( 'admin_print_footer_scripts', array( get_class(), 'admin_hook_styles' ), 5 );
		static::activate_field_type( get_class( $this ) );

		do_action( 'carbon_fields_field_activated', $this );
	}

	/**
	 * Activate a field type
	 * 
	 * @param string $class_name
	 */
	public static function activate_field_type( $class_name ) {
		if ( in_array( $class_name, static::$activated_field_types ) ) {
			return;
		}

		add_action( 'admin_print_footer_scripts', array( $class_name, 'admin_enqueue_scripts' ), 5 );
		call_user_func( array( $class_name, 'field_type_activated' ) );

		static::$activated_field_types[] = $class_name;
	}

	/**
	 * Prepare the field type for use
	 * Called once per field type when activated
	 */
	public static function field_type_activated() {}

	/**
	 * Get array of hierarchy field names
	 *
	 * @return array
	 */
	public function get_hierarchy() {
		return $this->hierarchy;
	}

	/**
	 * Set array of hierarchy field names
	 */
	public function set_hierarchy( $hierarchy ) {
		$this->hierarchy = $hierarchy;
	}

	/**
	 * Get array of hierarchy indexes
	 *
	 * @return array
	 */
	public function get_hierarchy_index() {
		return $this->hierarchy_index;
	}

	/**
	 * Set array of hierarchy indexes
	 */
	public function set_hierarchy_index( $hierarchy_index ) {
		$hierarchy_index = ( ! empty( $hierarchy_index ) ) ? $hierarchy_index : array( 0 );
		$this->hierarchy_index = $hierarchy_index;
	}

	/**
	 * Return whether the field is a root field and holds a single value
	 *
	 * @return bool
	 */
	public function is_simple_root_field() {
		$hierarchy = $this->get_hierarchy();
		return (
			empty( $hierarchy )
			&&
			in_array( $this->get_value_set()->get_type(), array( Value_Set::TYPE_SINGLE_VALUE, Value_Set::TYPE_MULTIPLE_PROPERTIES ) )
		);
	}

	/**
	 * Perform instance initialization
	 */
	public function init() {}

	/**
	 * Instance initialization when in the admin area
	 * Called during field boot
	 */
	public function admin_init() {}

	/**
	 * Enqueue scripts and styles in admin
	 * Called once per field type
	 */
	public static function admin_enqueue_scripts() {}

	/**
	 * Get value from datastore
	 *
	 * @param bool $fallback_to_default
	 * @return mixed
	 */
	protected function get_value_from_datastore( $fallback_to_default = true ) {
		$value = $this->get_datastore()->load( $this );

		if ( $value === null && $fallback_to_default ) {
			$value = $this->get_default_value();
		}

		return $value;
	}

	/**
	 * Load value from datastore
	 */
	public function load() {
		$this->set_value( $this->get_value_from_datastore() );
	}

	/**
	 * Save value to storage
	 */
	public function save() {
		$delete_on_save = ! in_array( $this->get_value_set()->get_type(), array( Value_Set::TYPE_SINGLE_VALUE, Value_Set::TYPE_MULTIPLE_PROPERTIES ) );
		$delete_on_save = apply_filters( 'carbon_fields_should_delete_field_value_on_save', $delete_on_save, $this );
		if ( $delete_on_save ) {
			$this->delete();
		}

		$save = apply_filters( 'carbon_fields_should_save_field_value', true, $this->get_value(), $this );
		if ( $save ) {
			$this->get_datastore()->save( $this );
		}
	}

	/**
	 * Delete value from storage
	 */
	public function delete() {
		$this->get_datastore()->delete( $this );
	}

	/**
	 * Load the field value from an input array based on it's name
	 *
	 * @param array $input Array of field names and values.
	 */
	public function set_value_from_input( $input ) {
		if ( isset( $input[ $this->get_name() ] ) ) {
			$this->set_value( $input[ $this->get_name() ] );
		} else {
			$this->clear_value();
		}
	}

	/**
	 * Return whether the datastore instance is the default one or has been overriden
	 *
	 * @return boolean
	 */
	public function has_default_datastore() {
		return $this->has_default_datastore;
	}

	/**
	 * Get the DataStore instance
	 *
	 * @return Datastore_Interface $datastore
	 */
	public function get_datastore() {
		return $this->datastore;
	}

	/**
	 * Set datastore instance
	 *
	 * @param Datastore_Interface $datastore
	 * @return object $this
	 */
	public function set_datastore( Datastore_Interface $datastore, $set_as_default = false ) {
		if ( $set_as_default && ! $this->has_default_datastore() ) {
			return $this; // datastore has been overriden with a custom one - abort changing to a default one
		}
		$this->datastore = $datastore;
		$this->has_default_datastore = $set_as_default;
		return $this;
	}

	/**
	 * Return the type of the container this field is in
	 *
	 * @return string
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Assign the type of the container this field is in
	 *
	 * @param string
	 * @return object $this
	 */
	public function set_context( $context ) {
		$this->context = $context;
		return $this;
	}

	/**
	 * Get the Value_Set object
	 *
	 * @return Value_Set
	 */
	public function get_value_set() {
		if ( $this->value_set === null ) {
			$this->set_value_set( new Value_Set() );
		}
		return $this->value_set;
	}

	/**
	 * Set the Value_Set object
	 *
	 * @param Value_Set $value_set
	 */
	public function set_value_set( $value_set ) {
		$this->value_set = $value_set;
	}

	/**
	 * Alias for $this->get_value_set()->get(); with fallback to default value
	 *
	 * @return mixed
	 */
	public function get_value() {
		if ( $this->get_value_set()->get() === null ) {
			$this->set_value( $this->get_default_value() );
		}
		return $this->get_value_set()->get();
	}

	/**
	 * Alias for $this->get_value_set()->get_set(); with fallback to default value
	 *
	 * @return array<array>
	 */
	public function get_full_value() {
		if ( $this->get_value_set()->get_set() === null ) {
			$this->set_value( $this->get_default_value() );
		}
		return $this->get_value_set()->get_set();
	}

	/**
	 * Return a differently formatted value for end-users
	 *
	 * @return mixed
	 */
	public function get_formatted_value() {
		$value = $this->get_value();
		if ( is_array( $value ) ) {
			$formatter = function( $value ) {
				$value['value'] = $value[ Value_Set::VALUE_PROPERTY ];
				unset( $value[ Value_Set::VALUE_PROPERTY ] );
				return $value;
			};
			if ( $this->get_value_set()->get_type() === Value_Set::TYPE_MULTIPLE_PROPERTIES ) {
				$value = $formatter( $value );
			} else if ( $this->get_value_set()->get_type() === Value_Set::TYPE_VALUE_SET ) {
				$value = array_map( $formatter, $value );
			}
		}
		return $value;
	}

	/**
	 * Alias for $this->get_value_set()->set( $value );
	 */
	public function set_value( $value ) {
		$this->get_value_set()->set( $value );
	}

	/**
	 * Clear the field value to a blank one (but not the default one)
	 */
	public function clear_value() {
		$this->get_value_set()->set( array() );
	}

	/**
	 * Get default field value
	 *
	 * @return mixed
	 */
	public function get_default_value() {
		return $this->default_value;
	}

	/**
	 * Set default field value
	 *
	 * @param mixed $default_value
	 */
	public function set_default_value( $default_value ) {
		$this->default_value = $default_value;
		return $this;
	}

	/**
	 * Return the field base name.
	 *
	 * @return string
	 */
	public function get_base_name() {
		return $this->base_name;
	}

	/**
	 * Set field base name as defined in the container.
	 */
	public function set_base_name( $name ) {
		$this->base_name = $name;
	}

	/**
	 * Return the field name
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Set field name.
	 * Use only if you are completely aware of what you are doing.
	 *
	 * @param string $name Field name, either sanitized or not
	 */
	public function set_name( $name ) {
		if ( empty( $name ) ) {
			Incorrect_Syntax_Exception::raise( 'Field name can\'t be empty' );
			return;
		}

		// symbols ]-[ are supported in a hidden way - required for widgets to work (WP imposes dashes and square brackets on field names)
		$regex = '/\A[a-z0-9_\-\[\]]+\z/';
		if ( ! preg_match( $regex, $name ) ) {
			Incorrect_Syntax_Exception::raise( 'Field name can only contain lowercase alphanumeric characters and underscores ("' . $name . '" passed).' );
			return;
		}

		$name_prefix = $this->get_name_prefix();
		$name = ( substr( $name, 0, strlen( $name_prefix ) ) !== $name_prefix ? $name_prefix . $name : $name );

		$this->name = $name;
	}

	/**
	 * Return the field name prefix
	 *
	 * @return string
	 */
	public function get_name_prefix() {
		return $this->name_prefix;
	}

	/**
	 * Set field name prefix
	 * Use only if you are completely aware of what you are doing.
	 *
	 * @param string $name_prefix
	 */
	public function set_name_prefix( $name_prefix ) {
		$name_prefix = strval( $name_prefix );
		$old_prefix_length = strlen( $this->name_prefix );
		$this->name_prefix = '';
		$this->set_name( substr( $this->get_name(), $old_prefix_length ) );

		$this->name_prefix = $name_prefix;
		$this->set_name( $this->name_prefix . $this->get_name() );
	}

	/**
	 * Return field label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Set field label.
	 *
	 * @param string $label If null, the label will be generated from the field name
	 */
	public function set_label( $label ) {
		if ( is_null( $label ) ) {
			// Try to guess field label from it's name
			$label = Helper::normalize_label( $this->get_name() );
		}

		$this->label = $label;
	}

	/**
	 * Get a key-value array of attributes
	 * 
	 * @return array
	 */
	public function get_attributes() {
		return $this->attributes;
	}

	/**
	 * Get an attribute value
	 * 
	 * @param  string $name
	 * @return string
	 */
	public function get_attribute( $name ) {
		return isset( $this->attributes[ $name ] ) ? $this->attributes[ $name ] : '';
	}

	/**
	 * Set an attribute and it's value
	 * 
	 * @param  string $name
	 * @param  string $value
	 * @return Field  $this
	 */
	public function set_attribute( $name, $value = '' ) {
		if ( ! in_array( $name, $this->allowed_attributes ) ) {
			Incorrect_Syntax_Exception::raise( 'Only the following attributes are allowed: ' . implode( ', ', $this->allowed_attributes ) . '.' );
			return $this;
		}
		$this->attributes[ $name ] = $value;
		return $this;
	}

	/**
	 * Return the field help text
	 *
	 * @return object $this
	 */
	public function get_help_text() {
		return $this->help_text;
	}

	/**
	 * Set additional text to be displayed during field render,
	 * containing information and guidance for the user
	 *
	 * @return object $this
	 */
	public function set_help_text( $help_text ) {
		$this->help_text = $help_text;
		return $this;
	}

	/**
	 * Alias for set_help_text()
	 *
	 * @see set_help_text()
	 * @return object $this
	 */
	public function help_text( $help_text ) {
		return $this->set_help_text( $help_text );
	}

	/**
	 * Return whether or not this value should be auto loaded.
	 *
	 * @return bool
	 */
	public function get_autoload() {
		return $this->autoload;
	}

	/**
	 * Whether or not this value should be auto loaded. Applicable to theme options only.
	 *
	 * @param bool $autoload
	 * @return object $this
	 */
	public function set_autoload( $autoload ) {
		$this->autoload = $autoload;
		return $this;
	}

	/**
	 * Return whether or not this field should be lazyloaded.
	 *
	 * @return bool
	 */
	public function get_lazyload() {
		return $this->lazyload;
	}

	/**
	 * Whether or not this field will be initialized when the field is in the viewport (visible).
	 *
	 * @param bool $lazyload
	 * @return object $this
	 */
	public function set_lazyload( $lazyload ) {
		$this->lazyload = $lazyload;
		return $this;
	}

	/**
	 * Get the field width.
	 *
	 * @return int $width
	 */
	public function get_width() {
		return $this->width;
	}

	/**
	 * Set the field width.
	 *
	 * @param int $width
	 * @return object $this
	 */
	public function set_width( $width ) {
		$this->width = (int) $width;
		return $this;
	}

	/**
	 * Get custom CSS classes.
	 *
	 * @return array<string>
	 */
	public function get_classes() {
		return $this->classes;
	}

	/**
	 * Set CSS classes that the container should use.
	 *
	 * @param string|array<string> $classes
	 * @return object $this
	 */
	public function set_classes( $classes ) {
		$this->classes = Helper::sanitize_classes( $classes );
		return $this;
	}

	/**
	 * Whether this field is mandatory for the user
	 *
	 * @param bool $required
	 * @return object $this
	 */
	public function set_required( $required = true ) {
		$this->required = $required;
		return $this;
	}

	/**
	 * Return whether this field is mandatory for the user
	 *
	 * @return bool
	 */
	public function is_required() {
		return $this->required;
	}

	/**
	 * HTML id attribute getter.
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * HTML id attribute setter
	 * @param string $id
	 */
	public function set_id( $id ) {
		$this->id = $id;
	}

	/**
	 * Set the field visibility conditional logic.
	 *
	 * @param array
	 */
	public function set_conditional_logic( $rules ) {
		$this->conditional_logic = $this->parse_conditional_rules( $rules );
		return $this;
	}

	/**
	 * Get the conditional logic rules
	 *
	 * @return array
	 */
	public function get_conditional_logic() {
		return $this->conditional_logic;
	}

	/**
	 * Validate and parse the conditional logic rules.
	 *
	 * @param array $rules
	 * @return array
	 */
	protected function parse_conditional_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			Incorrect_Syntax_Exception::raise( 'Conditional logic rules argument should be an array.' );
			return array();
		}

		$allowed_operators = array( '=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'INCLUDES', 'EXCLUDES' );

		$parsed_rules = array(
			'relation' => Helper::get_relation_type_from_array( $rules ),
			'rules' => array(),
		);

		foreach ( $rules as $key => $rule ) {
			if ( $key === 'relation' ) {
				continue; // Skip the relation key as it is already handled above
			}

			// Check if the rule is valid
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				Incorrect_Syntax_Exception::raise( 'Invalid conditional logic rule format. The rule should be an array with the "field" key set.' );
				return array();
			}

			// Fill in optional keys with defaults
			$rule = array_merge( array(
				'compare' => '=',
				'value' => '',
			), $rule );

			if ( ! in_array( $rule['compare'], $allowed_operators ) ) {
				Incorrect_Syntax_Exception::raise( 'Invalid conditional logic compare operator: <code>' . $rule['compare'] . '</code><br>Allowed operators are: <code>' .
				implode( ', ', $allowed_operators ) . '</code>' );
				return array();
			}

			if ( in_array( $rule['compare'], array( 'IN', 'NOT IN' ) ) && ! is_array( $rule['value'] ) ) {
				Incorrect_Syntax_Exception::raise( 'Invalid conditional logic value format. An array is expected, when using the "' . $rule['compare'] . '" operator.' );
				return array();
			}

			$parsed_rules['rules'][] = $rule;
		}

		return $parsed_rules;
	}

	/**
	 * Set the REST visibility of the field
	 * 
	 * @param bool $visible
	 * @return Field $this
	 */
	public function set_visible_in_rest_api( $visible ) {
		$this->visible_in_rest_api = $visible;
		return $this;
	}
	
	/**
	 * Get the REST visibility of the field
	 * 
	 * @return bool
	 */
	public function get_visible_in_rest_api() {
		return $this->visible_in_rest_api;
	}

	/**
	 * Configuration function for setting the field visibility in the response of the requests to the REST API
	 * 
	 * @param bool $visible
	 * @return Field $this
	 */
	public function show_in_rest( $visible = true ) {
		return $this->set_visible_in_rest_api( $visible );
	}

	/**
	 * Returns an array that holds the field data, suitable for JSON representation.
	 *
	 * @param bool $load  Should the value be loaded from the database or use the value from the current instance.
	 * @return array
	 */
	public function to_json( $load ) {
		if ( $load ) {
			$this->load();
		}

		$field_data = array(
			'id' => $this->get_id(),
			'type' => $this->get_type(),
			'label' => $this->get_label(),
			'name' => $this->get_name(),
			'base_name' => $this->get_base_name(),
			'value' => $this->get_value(),
			'default_value' => $this->get_default_value(),
			'attributes' => $this->get_attributes(),
			'help_text' => $this->get_help_text(),
			'context' => $this->get_context(),
			'required' => $this->is_required(),
			'lazyload' => $this->get_lazyload(),
			'width' => $this->get_width(),
			'classes' => $this->get_classes(),
			'conditional_logic' => $this->get_conditional_logic(),
		);

		return $field_data;
	}

	/**
	 * Hook administration scripts.
	 */
	public static function admin_hook_scripts() {
		wp_enqueue_media();
	}

	/**
	 * Hook administration styles.
	 */
	public static function admin_hook_styles() {
		wp_enqueue_style( 'thickbox' );
	}
}
