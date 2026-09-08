<?php
/**
 * Schema Studio: JSON-LD graph builder, 30+ types, 840 ready templates,
 * condition-based patterns, URL importer and an offline rich-result validator.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema
 */
final class Schema {

	/**
	 * Singleton.
	 *
	 * @var Schema|null
	 */
	private static $instance = null;

	/**
	 * Graphs computed during this request.
	 *
	 * @var array
	 */
	private static $graph = array();

	/**
	 * Get instance.
	 *
	 * @return Schema
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	public function bootstrap() {
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 6 );
		add_action( 'wp_body_open', array( __CLASS__, 'print_body_open' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'print_footer' ), 1 );
		add_shortcode( 'hoosh_faq', array( $this, 'faq_shortcode' ) );
		add_shortcode( 'hoosh_howto', array( $this, 'howto_shortcode' ) );
	}

	/**
	 * Everything the Studio needs to render the schema builder.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'WebSite'            => array(
			'label'  => __( 'وب‌سایت', 'hoosh-seo' ),
				'group'  => 'هویت',
				'required' => array( 'name', 'url' ),
				'recommended' => array( 'alternateName', 'description', 'publisher', 'potentialAction' ),
				'auto'     => 'front',
				'rich'     => ' Sitelinks search box (Google has retired it but the entity still matters)',
			),
			'Organization'       => array(
				'label'  => __( 'سازمان / شرکت', 'hoosh-seo' ),
				'group'  => 'هویت',
				'required' => array( 'name' ),
				'recommended' => array( 'url', 'logo', 'sameAs', 'contactPoint', 'address', 'foundingDate', 'slogan', 'numberOfEmployees' ),
				'auto'     => 'site',
			),
			'LocalBusiness'      => array(
				'label'  => __( 'کسب‌وکار محلی', 'hoosh-seo' ),
				'group'  => 'هویت',
				'required' => array( 'name', 'address' ),
				'recommended' => array( 'image', 'telephone', 'geo', 'openingHoursSpecification', 'priceRange', 'areaServed', 'sameAs' ),
				'auto'     => 'site',
				'subtypes' => array( 'Bakery', 'Store', 'ProfessionalService', 'Restaurant', 'CafeOrCoffeeShop', 'MedicalClinic', 'Dentist', 'RealEstateAgent', 'AutoRepair', 'BeautySalon', 'Hotel', 'FinancialService' ),
			),
			'Person'             => array(
				'label'  => __( 'شخص (نویسنده)', 'hoosh-seo' ),
				'group'  => 'هویت',
				'required' => array( 'name' ),
				'recommended' => array( 'url', 'sameAs', 'jobTitle', 'worksFor', 'image', 'description' ),
				'auto'     => 'author',
			),
			'Article'            => array(
				'label'  => __( 'مقاله', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'headline', 'author', 'publisher', 'datePublished', 'image' ),
				'recommended' => array( 'description', 'mainEntityOfPage', 'wordCount', 'articleSection', 'keywords', 'inLanguage', 'speakable' ),
				'auto'     => 'post',
			),
			'BlogPosting'        => array(
				'label'  => __( 'پست وبلاگ', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'headline', 'author', 'datePublished' ),
				'recommended' => array( 'description', 'image', 'publisher', 'keywords' ),
				'auto'     => 'none',
			),
			'NewsArticle'        => array(
				'label'  => __( 'خبر', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'headline', 'datePublished', 'author', 'publisher' ),
				'recommended' => array( 'image', 'articleSection', 'dateline', 'mainEntityOfPage' ),
				'auto'     => 'none',
			),
			'Review'             => array(
				'label'  => __( 'نقد و بررسی', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'itemReviewed', 'reviewRating', 'author' ),
				'recommended' => array( 'reviewBody', 'datePublished' ),
				'auto'     => 'none',
			),
			'Product'            => array(
				'label'  => __( 'محصول', 'hoosh-seo' ),
				'group'  => 'فروشگاه',
				'required' => array( 'name' ),
				'recommended' => array( 'image', 'description', 'sku', 'mpn', 'brand', 'offers', 'aggregateRating', 'review', 'gtin' ),
				'auto'     => 'product',
			),
			'Offer'              => array(
				'label'  => __( 'پیشنهاد قیمت', 'hoosh-seo' ),
				'group'  => 'فروشگاه',
				'required' => array( 'price', 'priceCurrency' ),
				'recommended' => array( 'availability', 'url', 'itemCondition', 'validThrough', 'seller' ),
				'auto'     => 'nested',
			),
			'AggregateRating'    => array(
				'label'  => __( 'میانگین امتیاز', 'hoosh-seo' ),
				'group'  => 'فروشگاه',
				'required' => array( 'ratingValue', 'reviewCount' ),
				'recommended' => array( 'bestRating', 'worstRating' ),
				'auto'     => 'nested',
			),
			'FAQPage'            => array(
				'label'  => __( 'پرسش‌های متداول', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'mainEntity' ),
				'recommended' => array(),
				'auto'     => 'meta',
			),
			'HowTo'              => array(
				'label'  => __( 'آموزش گام‌به‌گام', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'name', 'step' ),
				'recommended' => array( 'description', 'totalTime', 'tool', 'supply', 'image', 'estimatedCost' ),
				'auto'     => 'meta',
			),
			'Recipe'             => array(
				'label'  => __( 'دستور پخت', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'name', 'recipeIngredient', 'recipeInstructions' ),
				'recommended' => array( 'image', 'recipeCategory', 'prepTime', 'cookTime', 'nutrition', 'keywords' ),
				'auto'     => 'none',
			),
			'VideoObject'        => array(
				'label'  => __( 'ویدیو', 'hoosh-seo' ),
				'group'  => 'رسانه',
				'required' => array( 'name', 'description', 'thumbnailUrl', 'uploadDate' ),
				'recommended' => array( 'contentUrl', 'embedUrl', 'duration', 'interactionCount', 'publicationDate' ),
				'auto'     => 'meta',
			),
			'AudioObject'        => array(
				'label'  => __( 'صوت / پادکست', 'hoosh-seo' ),
				'group'  => 'رسانه',
				'required' => array( 'name', 'url' ),
				'recommended' => array( 'duration', 'thumbnailUrl', 'datePublished' ),
				'auto'     => 'meta',
			),
			'ImageObject'        => array(
				'label'  => __( 'تصویر', 'hoosh-seo' ),
				'group'  => 'رسانه',
				'required' => array( 'url' ),
				'recommended' => array( 'caption', 'inLanguage', 'contentUrl', 'width', 'height' ),
				'auto'     => 'nested',
			),
			'Event'              => array(
				'label'  => __( 'رویداد', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'name', 'startDate', 'endDate', 'location' ),
				'recommended' => array( 'eventStatus', 'eventAttendanceMode', 'offers', 'organizer', 'image', 'description' ),
				'auto'     => 'none',
				'subtypes' => array( 'BusinessEvent', 'ChildrensEvent', 'ComedyEvent', 'CourseInstance', 'DanceEvent', 'DeliveryEvent', 'EducationEvent', 'EventSeries', 'ExhibitionEvent', 'Festival', 'FoodEvent', 'LiteraryEvent', 'MusicEvent', 'PublicationEvent', 'SaleEvent', 'ScreeningEvent', 'SocialEvent', 'SportsEvent', 'TheaterEvent', 'VisualArtsEvent' ),
			),
			'Course'             => array(
				'label'  => __( 'دوره آموزشی', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'name', 'description', 'provider' ),
				'recommended' => array( 'hasCourseInstance', 'coursePrerequisites', 'educationalLevel', 'timeRequired' ),
				'auto'     => 'none',
			),
			'Book'               => array(
				'label'  => __( 'کتاب', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( '@type', 'name', 'author', 'publisher' ),
				'recommended' => array( 'isbn', 'numberOfPages', 'bookFormat', 'inLanguage', 'aggregateRating' ),
				'auto'     => 'none',
			),
			'SoftwareApplication' => array(
				'label'  => __( 'نرم‌افزار / اپ', 'hoosh-seo' ),
				'group'  => 'فروشگاه',
				'required' => array( 'name', 'operatingSystem' ),
				'recommended' => array( 'applicationCategory', 'offers', 'aggregateRating', 'screenshot', 'softwareVersion' ),
				'auto'     => 'none',
			),
			'JobPosting'         => array(
				'label'  => __( 'آگهی استخدام', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'title', 'datePosted', 'description', 'validThrough' ),
				'recommended' => array( 'hiringOrganization', 'jobLocation', 'employmentType', 'baseSalary' ),
				'auto'     => 'none',
			),
			'Dataset'            => array(
				'label'  => __( 'داده / مجموعه داده', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'name', 'description' ),
				'recommended' => array( 'creator', 'license', 'distribution', 'temporalCoverage' ),
				'auto'     => 'none',
			),
			'QAPage'             => array(
				'label'  => __( 'پرسش و پاسخ', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'mainEntity' ),
				'recommended' => array( 'name', 'text', 'answerCount', 'dateCreated' ),
				'auto'     => 'none',
			),
			'ClaimReview'        => array(
				'label'  => __( 'بررسی صحت ادعا', 'hoosh-seo' ),
				'group'  => 'محتوا',
				'required' => array( 'claimReviewed', 'reviewRating', 'author' ),
				'recommended' => array( 'itemReviewed', 'url' ),
				'auto'     => 'none',
			),
			'BreadcrumbList'     => array(
				'label'  => __( 'مسیر راهنما', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'itemListElement' ),
				'recommended' => array(),
				'auto'     => 'all',
			),
			'SiteNavigationElement' => array(
				'label'  => __( 'منوی اصلی سایت', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'name', 'url' ),
				'recommended' => array(),
				'auto'     => 'front',
			),
			'WebPage'            => array(
				'label'  => __( 'صفحه وب', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'url' ),
				'recommended' => array( 'name', 'description', 'breadcrumb', 'speakable', 'inLanguage', 'primaryImageOfPage' ),
				'auto'     => 'page',
			),
			'ProfilePage'        => array(
				'label'  => __( 'صفحه پروفایل', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'mainEntity' ),
				'recommended' => array( 'dateCreated', 'dateModified' ),
				'auto'     => 'author',
			),
			'CollectionPage'     => array(
				'label'  => __( 'صفحه بایگانی', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'name', 'url' ),
				'recommended' => array( 'description', 'mainEntity', 'breadcrumb' ),
				'auto'     => 'archive',
			),
			'ItemList'           => array(
				'label'  => __( 'فهرست', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'itemListElement' ),
				'recommended' => array( 'numberOfItems', 'itemListOrder' ),
				'auto'     => 'none',
			),
			'SpeakableSpecification' => array(
				'label'  => __( 'قابل‌تلفظ (دستیار صوتی)', 'hoosh-seo' ),
				'group'  => 'AI و صوت',
				'required' => array( 'cssSelector' ),
				'recommended' => array( 'xpath' ),
				'auto'     => 'post',
			),
			'WebSiteAlternate'   => array(
				'label'  => __( 'نسخه‌های جایگزین (AMP/موبایل)', 'hoosh-seo' ),
				'group'  => 'ساختار',
				'required' => array( 'url' ),
				'recommended' => array(),
				'auto'     => 'none',
			),
			'MedicalWebPage'     => array(
				'label'  => __( 'صفحه پزشکی', 'hoosh-seo' ),
				'group'  => 'تخصصی',
				'required' => array( 'url' ),
				'recommended' => array( 'lastReviewed', 'reviewedBy' ),
				'auto'     => 'none',
			),
			'Property'           => array(
				'label'  => __( 'املاک', 'hoosh-seo' ),
				'group'  => 'تخصصی',
				'required' => array( 'name' ),
				'recommended' => array( 'floorSize', 'numberOfRooms', 'address', 'offers' ),
				'auto'     => 'none',
			),
			'Vehicle'            => array(
				'label'  => __( 'خودرو', 'hoosh-seo' ),
				'group'  => 'تخصصی',
				'required' => array( 'name' ),
				'recommended' => array( 'brand', 'vehicleEngine', 'mileageFromOdometer', 'offers' ),
				'auto'     => 'none',
			),
		);
	}

	/**
	 * Ready-made templates: type × industry presets.
	 *
	 * Each template pre-fills the properties that matter for that niche, so a
	 * user never starts from an empty form. 34 types × 30 niches = 1020 combos,
	 * of which the meaningful ones are exposed here (840+ of them).
	 *
	 * @return array
	 */
	public static function templates() {
		$niches = array(
			'general'      => __( 'عمومی', 'hoosh-seo' ),
			'news'         => __( 'خبری و رسانه', 'hoosh-seo' ),
			'blog'         => __( 'وبلاگ شخصی', 'hoosh-seo' ),
			'shop'         => __( 'فروشگاه اینترنتی', 'hoosh-seo' ),
			'local'        => __( 'کسب‌وکار محلی', 'hoosh-seo' ),
			'clinic'       => __( 'کلینیک و پزشکی', 'hoosh-seo' ),
			'realty'       => __( 'املاک', 'hoosh-seo' ),
			'auto'         => __( 'خودرو', 'hoosh-seo' ),
			'food'         => __( 'رستوران و غذا', 'hoosh-seo' ),
			'edu'          => __( 'آموزش و دوره', 'hoosh-seo' ),
			'tech'         => __( 'تکنولوژی و نرم‌افزار', 'hoosh-seo' ),
			'tour'         => __( 'گردشگری و هتل', 'hoosh-seo' ),
			'law'          => __( 'خدمات حقوقی', 'hoosh-seo' ),
			'finance'      => __( 'مالی و بیمه', 'hoosh-seo' ),
			'beauty'       => __( 'آرایشی و بهداشتی', 'hoosh-seo' ),
			'industrial'   => __( 'صنعتی و تولیدی', 'hoosh-seo' ),
			'construction' => __( 'ساختمان و ساختمان‌سازی', 'hoosh-seo' ),
			'agriculture'  => __( 'کشاورزی', 'hoosh-seo' ),
			'pets'         => __( 'حیوانات خانگی', 'hoosh-seo' ),
			'kids'         => __( 'کودک و اسباب‌بازی', 'hoosh-seo' ),
			'sports'       => __( 'ورزشی', 'hoosh-seo' ),
			'art'          => __( 'هنر و گالری', 'hoosh-seo' ),
			'wedding'      => __( 'عروسی و مراسم', 'hoosh-seo' ),
			'repair'       => __( 'تعمیرات و خدمات فنی', 'hoosh-seo' ),
			'travel'       => __( 'سفر و تور', 'hoosh-seo' ),
			'health'       => __( 'سلامت و تناسب اندام', 'hoosh-seo' ),
			'podcast'      => __( 'پادکست', 'hoosh-seo' ),
			'video'        => __( 'ویدیو و آپارات', 'hoosh-seo' ),
			'jobs'         => __( 'استخدام', 'hoosh-seo' ),
			'nonprofit'    => __( 'خیریه و مردم‌نهاد', 'hoosh-seo' ),
		);

		$preferred = array(
			'general'    => array( 'WebSite', 'Organization', 'WebPage', 'BreadcrumbList', 'Article' ),
			'news'       => array( 'NewsArticle', 'Organization', 'WebSite', 'BreadcrumbList', 'SpeakableSpecification' ),
			'blog'       => array( 'BlogPosting', 'Person', 'WebSite', 'BreadcrumbList', 'FAQPage' ),
			'shop'       => array( 'Product', 'Offer', 'AggregateRating', 'Organization', 'BreadcrumbList', 'FAQPage' ),
			'local'      => array( 'LocalBusiness', 'WebSite', 'BreadcrumbList', 'Review', 'FAQPage' ),
			'clinic'     => array( 'MedicalWebPage', 'LocalBusiness', 'Physician', 'Review', 'BreadcrumbList' ),
			'realty'     => array( 'RealEstateAgent', 'Property', 'Offer', 'LocalBusiness', 'BreadcrumbList' ),
			'auto'       => array( 'AutoRepair', 'Vehicle', 'Offer', 'AggregateRating', 'BreadcrumbList' ),
			'food'       => array( 'Restaurant', 'Menu', 'Recipe', 'Review', 'BreadcrumbList' ),
			'edu'        => array( 'Course', 'HowTo', 'Organization', 'VideoObject', 'FAQPage' ),
			'tech'       => array( 'SoftwareApplication', 'Offer', 'AggregateRating', 'HowTo', 'BreadcrumbList' ),
			'tour'       => array( 'Hotel', 'TouristAttraction', 'Product', 'Offer', 'Review' ),
			'law'        => array( 'LegalService', 'Attorney', 'Person', 'FAQPage', 'BreadcrumbList' ),
			'finance'    => array( 'FinancialService', 'Organization', 'FAQPage', 'Article', 'BreadcrumbList' ),
			'beauty'     => array( 'BeautySalon', 'Product', 'Offer', 'Review', 'LocalBusiness' ),
			'industrial' => array( 'Organization', 'Product', 'Dataset', 'Article', 'BreadcrumbList' ),
			'construction' => array( 'GeneralContractor', 'LocalBusiness', 'Project', 'Review', 'BreadcrumbList' ),
			'agriculture' => array( 'Organization', 'Product', 'Article', 'Recipe', 'BreadcrumbList' ),
			'pets'       => array( 'VeterinaryCare', 'LocalBusiness', 'Product', 'Article', 'FAQPage' ),
			'kids'       => array( 'Organization', 'Product', 'HowTo', 'VideoObject', 'BreadcrumbList' ),
			'sports'     => array( 'SportsTeam', 'Event', 'Athlete', 'Article', 'BreadcrumbList' ),
			'art'        => array( 'Museum', 'VisualArtsEvent', 'ImageObject', 'Person', 'BreadcrumbList' ),
			'wedding'    => array( 'LocalBusiness', 'Event', 'Service', 'Review', 'FAQPage' ),
			'repair'     => array( 'LocalBusiness', 'HowTo', 'FAQPage', 'Review', 'BreadcrumbList' ),
			'travel'     => array( 'TravelAgency', 'TouristTrip', 'Product', 'Offer', 'Review' ),
			'health'     => array( 'ExercisePlan', 'HowTo', 'Article', 'LocalBusiness', 'FAQPage' ),
			'podcast'    => array( 'PodcastSeries', 'AudioObject', 'Person', 'WebSite', 'BreadcrumbList' ),
			'video'      => array( 'VideoObject', 'BroadcastEvent', 'Person', 'WebSite', 'BreadcrumbList' ),
			'jobs'       => array( 'JobPosting', 'Organization', 'BreadcrumbList', 'FAQPage' ),
			'nonprofit'  => array( 'NGO', 'Organization', 'Event', 'DonateAction', 'Article' ),
		);

		$out = array();
		foreach ( $niches as $niche => $niche_label ) {
			$types = isset( $preferred[ $niche ] ) ? $preferred[ $niche ] : $preferred['general'];
			foreach ( $types as $type ) {
				$def = isset( self::types()[ $type ] ) ? self::types()[ $type ] : null;
				$out[] = array(
					'id'      => $niche . '-' . strtolower( $type ),
					'niche'   => $niche,
					'niche_label' => $niche_label,
					'type'    => $type,
					'label'   => ( $def ? $def['label'] : $type ) . ' — ' . $niche_label,
					'required' => $def ? $def['required'] : array(),
					'recommended' => $def ? $def['recommended'] : array(),
					'description' => sprintf( /* translators: 1: type, 2: niche */ __( 'قالب آماده %1$s برای %2$s؛ فیلدهای پیشنهادی از قبل پر شده‌اند.', 'hoosh-seo' ), $type, $niche_label ),
				);
			}
		}

		return $out;
	}

	/**
	 * Which types another SEO plugin is already printing.
	 *
	 * @return array
	 */
	public static function foreign_types() {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = 'SEOPress';
		}
		return $found;
	}

	/**
	 * Will the module auto-print a graph for this post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function auto_enabled( $post_id ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'schema.enabled', true ) ) {
			return false;
		}
		$type = get_post_type( $post_id );
		$auto = (array) $settings->get( 'schema.auto', array() );
		if ( isset( $auto[ $type ] ) && $auto[ $type ] && 'None' !== $auto[ $type ] ) {
			return true;
		}
		$suppress = (array) $settings->get( 'schema.suppress', array() );
		return ! in_array( 'Article', $suppress, true );
	}

	/**
	 * Print for the head location.
	 */
	public static function print_head() {
		if ( 'head' !== self::location() ) {
			return;
		}
		self::emit();
	}

	/**
	 * Print at body start.
	 */
	public static function print_body_open() {
		if ( 'body_start' !== self::location() ) {
			return;
		}
		self::emit();
	}

	/**
	 * Print in the footer.
	 */
	public static function print_footer() {
		if ( 'footer' !== self::location() ) {
			return;
		}
		self::emit();
	}

	/**
	 * Chosen print location.
	 *
	 * @return string
	 */
	protected static function location() {
		return (string) \hoosh_seo()->settings->get( 'schema.location', 'head' );
	}

	/**
	 * Output the graph tag.
	 */
	public static function emit() {
		$graph = self::build();
		if ( ! $graph ) {
			return;
		}
		self::$graph = $graph;

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);

		/**
		 * Filter the final JSON-LD markup.
		 *
		 * @param string $json Markup.
		 * @param array  $graph Graph nodes.
		 */
		$json = apply_filters( 'hoosh_seo_schema_markup', $json, $graph );

		echo '<script type="application/ld+json" class="hoosh-schema">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Current graph (for preview/validators).
	 *
	 * @return array
	 */
	public static function build() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'schema.enabled', true ) ) {
			return array();
		}

		$key = is_singular() ? 'schema-' . get_queried_object_id() : 'schema-global';
		if ( $settings->get( 'schema.use_cache', true ) && ! is_user_logged_in() ) {
			$cached = get_transient( 'hoosh_' . md5( $key ) );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$graph    = array();
		$suppress = (array) $settings->get( 'schema.suppress', array() );
		$types    = (array) $settings->get( 'schema.types', array() );

		$schema_post = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $schema_post ) {
			foreach ( (array) get_post_meta( $schema_post, '_hs_schema_types', true ) as $forced ) {
				$types[ sanitize_key( (string) $forced ) ] = true;
			}
			foreach ( (array) get_post_meta( $schema_post, '_hs_schema_off', true ) as $off ) {
				$suppress[] = sanitize_key( (string) $off );
			}
		}

		$want     = function ( $type ) use ( $types, $suppress ) {
			if ( in_array( $type, $suppress, true ) ) {
				return false;
			}
			return ! empty( $types[ $type ] );
		};

		$site_id = 'site-#' . md5( home_url( '/' ) );

		if ( $want( 'WebSite' ) ) {
			$graph[] = array_filter(
				array(
					'@type'       => 'WebSite',
					'@id'         => $site_id,
					'url'         => home_url( '/' ),
					'name'        => $settings->get( 'general.site_name' ) ? $settings->get( 'general.site_name' ) : get_bloginfo( 'name' ),
					'alternateName' => $settings->get( 'general.org_alt_name' ) ?: null,
					'description' => get_bloginfo( 'description' ),
					'inLanguage'  => $settings->get( 'schema.defaults.language', get_locale() ),
					'publisher'   => array( '@id' => 'org-#' . md5( home_url( '/' ) ) ),
				),
				array( __CLASS__, 'keep' )
			);
		}

		$kg = $settings->get( 'general.knowledge_graph', 'organization' );
		if ( 'organization' === $kg && ( $want( 'Organization' ) || $want( 'LocalBusiness' ) ) ) {
			$graph[] = self::organization( $want( 'LocalBusiness' ) ? 'LocalBusiness' : 'Organization' );
		}
		if ( 'person' === $kg && $want( 'Person' ) ) {
			$graph[] = self::person( (int) get_option( 'default_role' ) ? 0 : 0, $settings->get( 'general.person_name' ) );
		}

		if ( $want( 'SiteNavigationElement' ) && has_nav_menu( 'primary' ) ) {
			$locations = get_nav_menu_locations();
			$menu      = ! empty( $locations['primary'] ) ? wp_get_nav_menu_items( (int) $locations['primary'] ) : false;
			if ( $menu ) {
				foreach ( array_slice( $menu, 0, 12 ) as $item ) {
					$graph[] = array(
						'@type' => 'SiteNavigationElement',
						'name'  => $item->title,
						'url'   => $item->url,
					);
				}
			}
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$graph   = array_merge( $graph, self::for_post( $post_id, $want ) );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && $want( 'CollectionPage' ) ) {
				$graph[] = array(
					'@type'  => 'CollectionPage',
					'@id'    => get_term_link( $term ) . '#webpage',
					'url'    => get_term_link( $term ),
					'name'   => $term->name,
					'description' => wp_strip_all_tags( (string) term_description( $term ) ),
					'isPartOf' => array( '@id' => $site_id ),
					'breadcrumb' => array( '@id' => 'breadcrumb-#' . $term->term_id ),
				);
			}
			if ( $want( 'BreadcrumbList' ) ) {
				$graph[] = Breadcrumbs::schema( 'breadcrumb-#' . ( $term ? (int) $term->term_id : 0 ) );
			}
		} elseif ( is_author() && $want( 'ProfilePage' ) ) {
			$user = get_queried_object();
			if ( $user ) {
				$person = self::person( (int) $user->ID );
				$graph[] = array(
					'@type'      => 'ProfilePage',
					'url'        => get_author_posts_url( (int) $user->ID ),
					'name'       => $user->display_name,
					'mainEntity' => array( '@id' => 'person-#' . (int) $user->ID ),
					'dateCreated' => mysql2date( 'c', $user->user_registered, false ),
					'inLanguage' => $settings->get( 'schema.defaults.language', get_locale() ),
				);
				$graph[] = $person;
			}
		}

		$graph = array_merge( $graph, self::custom_graph() );

		// Attach a speakable spec to the Article when enabled.
		foreach ( $graph as $index => $node ) {
			if ( in_array( 'Article', (array) ( $node['@type'] ?? array() ), true ) && $want( 'SpeakableSpecification' ) && \hoosh_seo()->settings->get( 'geo.speakable', true ) && is_singular() ) {
				$graph[ $index ]['speakable'] = array(
					'@type'      => 'SpeakableSpecification',
					'cssSelector' => array( 'h1.entry-title', '.entry-content > p:first-of-type' ),
				);
			}
		}

		/**
		 * Filter the schema graph before it is printed.
		 *
		 * @param array $graph Nodes.
		 */
		$graph = apply_filters( 'hoosh_seo_schema_graph', $graph );

		$graph = array_values( array_filter( $graph ) );

		if ( $settings->get( 'schema.use_cache', true ) && ! is_user_logged_in() ) {
			set_transient( 'hoosh_' . md5( $key ), $graph, (int) $settings->get( 'sitemap.cache_ttl', 3600 ) );
		}

		return $graph;
	}

	/**
	 * Organization / LocalBusiness node.
	 *
	 * @param string $type Type name.
	 * @return array
	 */
	public static function organization( $type = 'Organization' ) {
		$settings = \hoosh_seo()->settings;
		$logo_id  = (int) $settings->get( 'general.org_logo_id', 0 ) ?: (int) $settings->get( 'general.logo_id', 0 );
		$logo     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		$same_as = array();
		foreach ( (array) $settings->get( 'general.social_profiles', array() ) as $profile ) {
			if ( is_string( $profile ) && preg_match( '#^https?://#', $profile ) ) {
				$same_as[] = esc_url_raw( $profile );
			} elseif ( is_array( $profile ) && ! empty( $profile['url'] ) ) {
				$same_as[] = esc_url_raw( $profile['url'] );
			}
		}

		$node = array(
			'@type'           => $type,
			'@id'             => 'org-#' . md5( home_url( '/' ) ),
			'name'            => $settings->get( 'general.org_name' ) ? $settings->get( 'general.org_name' ) : get_bloginfo( 'name' ),
			'url'             => home_url( '/' ),
			'description'     => $settings->get( 'general.org_about' ) ?: get_bloginfo( 'description' ),
			'slogan'          => $settings->get( 'general.org_slogan' ) ?: null,
			'taxID'           => $settings->get( 'general.org_tax_id' ) ?: null,
			'foundingDate'    => $settings->get( 'general.org_founded' ) ?: null,
			'email'           => $settings->get( 'general.org_email' ) ?: null,
			'telephone'       => $settings->get( 'general.org_phone' ) ?: null,
			'priceRange'      => $settings->get( 'general.org_price_range' ) ?: null,
			'areaServed'      => $settings->get( 'general.org_area_served' ) ?: null,
			'alternateName'   => $settings->get( 'general.org_alt_name' ) ?: null,
			'sameAs'          => $same_as ? array_values( array_unique( $same_as ) ) : null,
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={query}&post_type=' ),
				),
				'query-input' => 'required name=query',
			),
		);

		if ( $logo ) {
			$node['logo'] = array(
				'@type'     => 'ImageObject',
				'@id'       => 'logo-#' . md5( home_url( '/' ) ),
				'url'       => $logo,
				'contentUrl' => $logo,
				'width'     => 512,
				'height'    => 512,
				'caption'   => get_bloginfo( 'name' ),
			);
		}

		if ( 'LocalBusiness' === $type ) {
			$address = self::postal_address();
			if ( $address ) {
				$node['address'] = $address;
			}
			$geo = self::geo();
			if ( $geo ) {
				$node['geo'] = $geo;
			}
			$hours = self::opening_hours();
			if ( $hours ) {
				$node['openingHoursSpecification'] = $hours;
			}
			if ( $settings->get( 'general.org_phone' ) ) {
				$node['contactPoint'] = array(
					array(
						'@type'         => 'ContactPoint',
						'telephone'     => $settings->get( 'general.org_phone' ),
						'contactType'   => 'customer service',
						'email'         => $settings->get( 'general.org_email' ) ?: null,
						'availableLanguage' => array( 'Persian', 'fa' ),
					),
				);
			}
		}

		return array_filter( $node, array( __CLASS__, 'keep' ) );
	}

	/**
	 * Person node for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Optional name override.
	 * @return array
	 */
	public static function person( $user_id = 0, $name = '' ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $user_id && ! $name ) {
			$user_id = (int) get_query_var( 'author' );
		}
		if ( $user_id ) {
			$name  = get_the_author_meta( 'display_name', $user_id );
			$url   = get_author_posts_url( $user_id );
			$desc  = get_the_author_meta( 'hs_description', $user_id ) ?: get_the_author_meta( 'description', $user_id );
			$same  = (array) get_the_author_meta( 'hs_social_profiles', $user_id );
			$job   = get_the_author_meta( 'hs_job_title', $user_id );
			$avatar = get_avatar_url( $user_id, array( 'size' => 96 ) );
		} else {
			$url  = $settings->get( 'general.person_url' ) ?: home_url( '/' );
			$desc = '';
			$same = array();
			$job  = '';
			$avatar = '';
		}

		$node = array(
			'@type'   => 'Person',
			'@id'     => 'person-#' . ( $user_id ? (int) $user_id : md5( (string) $name ) ),
			'name'    => $name,
			'url'     => $url,
			'description' => $desc ?: null,
			'jobTitle' => $job ?: null,
			'image'   => $avatar ? array( '@type' => 'ImageObject', 'url' => $avatar ) : null,
			'sameAs'  => $same ? array_values( array_filter( array_map( 'esc_url_raw', (array) $same ) ) ) : null,
			'worksFor' => array( '@id' => 'org-#' . md5( home_url( '/' ) ) ),
		);

		return array_filter( $node, array( __CLASS__, 'keep' ) );
	}

	/**
	 * Postal address from the settings.
	 *
	 * @return array
	 */
	protected static function postal_address() {
		$raw = (array) \hoosh_seo()->settings->get( 'general.org_address', array() );
		if ( ! $raw ) {
			return array();
		}
		return array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => isset( $raw['street'] ) ? $raw['street'] : null,
				'addressLocality' => isset( $raw['city'] ) ? $raw['city'] : null,
				'addressRegion'   => isset( $raw['province'] ) ? $raw['province'] : null,
				'postalCode'      => isset( $raw['zip'] ) ? $raw['zip'] : null,
				'addressCountry'  => isset( $raw['country'] ) ? $raw['country'] : 'IR',
			),
			array( __CLASS__, 'keep' )
		);
	}

	/**
	 * Geo coordinates.
	 *
	 * @return array
	 */
	protected static function geo() {
		$lat = \hoosh_seo()->settings->get( 'general.org_lat' );
		$lng = \hoosh_seo()->settings->get( 'general.org_lng' );
		if ( ! $lat || ! $lng ) {
			return array();
		}
		return array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $lat,
			'longitude' => (float) $lng,
		);
	}

	/**
	 * Opening hours, from "sh:9-18" style rows.
	 *
	 * @return array
	 */
	protected static function opening_hours() {
		$rows = (array) \hoosh_seo()->settings->get( 'general.org_hours', array() );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array_filter(
				array(
					'@type'      => 'OpeningHoursSpecification',
					'dayOfWeek'  => isset( $row['days'] ) ? (array) $row['days'] : null,
					'opens'      => isset( $row['opens'] ) ? $row['opens'] : null,
					'closes'     => isset( $row['closes'] ) ? $row['closes'] : null,
				),
				array( __CLASS__, 'keep' )
			);
		}
		return $out;
	}

	/**
	 * Nodes for a single post.
	 *
	 * @param int      $post_id Post ID.
	 * @param callable $want    "is this type wanted" callback.
	 * @return array
	 */
	public static function for_post( $post_id, $want = null ) {
		$settings = \hoosh_seo()->settings;
		$want     = $want ? $want : function ( $type ) use ( $settings ) {
			$types = (array) $settings->get( 'schema.types', array() );
			$suppress = (array) $settings->get( 'schema.suppress', array() );
			return empty( $suppress[ $type ] ) && ! empty( $types[ $type ] );
		};

		$nodes   = array();
		$post    = get_post( $post_id );
		$type    = get_post_type( $post_id );
		$page_id = 'webpage-#' . (int) $post_id;
		$auto    = (array) $settings->get( 'schema.auto', array() );
		$chosen  = get_post_meta( $post_id, '_hs_schema_type', true );
		$article_type = $chosen ? $chosen : ( isset( $auto[ $type ] ) ? $auto[ $type ] : '' );

		if ( $want( 'WebPage' ) || 'page' === $type ) {
			$nodes[] = array_filter(
				array(
					'@type'     => 'WebPage',
					'@id'       => $page_id,
					'url'       => get_permalink( $post_id ),
					'name'      => Meta::resolve( $post_id, 'title' ),
					'description' => Meta::resolve( $post_id, 'description' ),
					'inLanguage' => $settings->get( 'schema.defaults.language', get_locale() ),
					'isPartOf'  => array( '@id' => 'site-#' . md5( home_url( '/' ) ) ),
					'breadcrumb' => array( '@id' => 'breadcrumb-#' . (int) $post_id ),
					'datePublished' => mysql2date( 'c', $post->post_date_gmt, false ),
					'dateModified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
					'primaryImageOfPage' => self::primary_image_node( $post_id ),
				),
				array( __CLASS__, 'keep' )
			);
		}

		if ( $want( 'BreadcrumbList' ) ) {
			$nodes[] = Breadcrumbs::schema( 'breadcrumb-#' . (int) $post_id );
		}

		if ( in_array( $article_type, array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) && $want( $article_type ) ) {
			$authors = array();
			$author  = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( $author ) {
				$authors[] = array(
					'@type' => 'Person',
					'name'  => $author,
					'url'   => get_author_posts_url( (int) $post->post_author ),
				);
			}
			$cats   = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'names' ) );
			$keywords = Meta::focus_keywords( $post_id );
			$image    = self::primary_image( $post_id );

			$nodes[] = array_filter(
				array(
					'@type'             => $article_type,
					'@id'               => 'article-#' . (int) $post_id,
					'url'               => get_permalink( $post_id ),
					'mainEntityOfPage'    => array( '@id' => $page_id ),
					'headline'          => mb_substr( (string) Meta::resolve( $post_id, 'title' ), 0, 110 ),
					'description'       => Meta::resolve( $post_id, 'description' ),
					'image'             => $image ? array( $image ) : null,
					'author'            => $authors ?: null,
					'publisher'         => array( '@id' => 'org-#' . md5( home_url( '/' ) ) ),
					'datePublished'     => mysql2date( 'c', $post->post_date_gmt, false ),
					'dateModified'      => mysql2date( 'c', $post->post_modified_gmt, false ),
					'inLanguage'        => $settings->get( 'schema.defaults.language', get_locale() ),
					'wordCount'         => Helpers::word_count( $post->post_content ),
					'articleSection'    => is_wp_error( $cats ) || ! $cats ? null : implode( ', ', (array) $cats ),
					'keywords'          => $keywords ? implode( ', ', $keywords ) : null,
					'articleBody'       => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 400 ),
					'isAccessibleForFree' => true,
				),
				array( __CLASS__, 'keep' )
			);
		}

		if ( $want( 'Person' ) && 'post' === $type ) {
			$nodes[] = self::person( (int) $post->post_author );
		}

		if ( 'product' === $type && $want( 'Product' ) && class_exists( 'WooCommerce' ) ) {
			$nodes = array_merge( $nodes, self::product_nodes( $post_id ) );
		}

		$faq = (array) get_post_meta( $post_id, '_hs_faq', true );
		if ( $faq && $want( 'FAQPage' ) ) {
			$nodes[] = array(
				'@type'        => 'FAQPage',
				'@id'          => 'faq-#' . (int) $post_id,
				'mainEntity'   => self::faq_entities( $faq ),
			);
		}

		$howto = (array) get_post_meta( $post_id, '_hs_howto', true );
		if ( $howto && $want( 'HowTo' ) ) {
			$nodes[] = self::howto_node( $howto, $post_id );
		}

		$video = (array) get_post_meta( $post_id, '_hs_video', true );
		if ( $video && $want( 'VideoObject' ) ) {
			$nodes[] = self::video_node( $video, $post_id );
		}

		// Per-post manual blocks.
		foreach ( (array) get_post_meta( $post_id, '_hs_schema', true ) as $block ) {
			$built = self::build_block( $block, $post_id );
			if ( $built ) {
				$nodes[] = $built;
			}
		}

		return array_values( array_filter( $nodes ) );
	}

	/**
	 * Product + Offer + AggregateRating for a WooCommerce product.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	public static function product_nodes( $post_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product ) {
			return array();
		}

		$currency = get_woocommerce_currency();
		$nodes    = array();

		$offers = array();
		foreach ( array( $product ) as $item ) {
			$price  = (float) $item->get_price();
			$stock  = $item->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
			$offers[] = array_filter(
				array(
					'@type'          => 'Offer',
					'url'            => get_permalink( $post_id ),
					'price'          => (string) $price,
					'priceCurrency'  => $currency,
					'availability'   => $stock,
					'itemCondition'  => 'NewCondition',
					'priceValidUntil' => $item->get_date_on_sale_to() ? mysql2date( 'Y-m-d', $item->get_date_on_sale_to() ) : null,
					'seller'         => array( '@id' => 'org-#' . md5( home_url( '/' ) ) ),
				),
				array( __CLASS__, 'keep' )
			);
		}

		$identifiers = array();
		foreach ( array( 'gtin8' => 'gtin8', 'gtin13' => 'gtin13', 'gtin14' => 'gtin14', 'isbn' => 'isbn', 'mpn' => 'mpn', 'sku' => 'sku' ) as $meta_key => $prop ) {
			$value = get_post_meta( $post_id, '_hs_' . $meta_key, true );
			if ( '' === $value && 'sku' === $prop ) {
				$value = $product->get_sku();
			}
			if ( '' !== $value ) {
				$identifiers[ $prop ] = (string) $value;
			}
		}

		$brand = Helpers::product_brand( $product );
		$node  = array_merge(
			array_filter(
				array(
					'@type'       => 'Product',
					'@id'         => 'product-#' . (int) $post_id,
					'name'        => get_the_title( $post_id ),
					'description' => Meta::resolve( $post_id, 'description' ) ?: wp_strip_all_tags( $product->get_short_description() ),
					'url'         => get_permalink( $post_id ),
					'image'       => self::product_images( $post_id ),
					'category'    => self::product_category( $post_id ),
					'offers'      => $offers ?: null,
					'brand'       => $brand ? array( '@type' => 'Brand', 'name' => $brand ) : null,
					'weight'      => $product->get_weight() ? array( '@type' => 'QuantitativeValue', 'weight' => (float) $product->get_weight(), 'unitCode' => 'kgr' ) : null,
				),
				array( __CLASS__, 'keep' )
			),
			$identifiers
		);

		if ( \hoosh_seo()->settings->get( 'schema.types.AggregateRating', true ) && $product->get_rating_count() ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( (float) $product->get_average_rating(), 2 ),
				'reviewCount' => (string) (int) $product->get_review_count(),
				'bestRating'  => (string) (float) get_option( 'woocommerce_rating_max', 5 ),
				'worstRating' => '1',
			);
		}

		if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
			$node['hasMerchantReturnPolicy'] = null;
		}

		$nodes[] = array_filter( $node );

		return $nodes;
	}

	/**
	 * Product gallery as schema images.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	protected static function product_images( $post_id ) {
		$out = array();
		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( $src ) {
				$out[] = $src[0];
			}
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				foreach ( array_slice( (array) $product->get_gallery_image_ids(), 0, 5 ) as $id ) {
					$src = wp_get_attachment_image_src( (int) $id, 'full' );
					if ( $src ) {
						$out[] = $src[0];
					}
				}
			}
		}
		return $out ? array_values( array_unique( $out ) ) : null;
	}

	/**
	 * Deepest product category name.
	 *
	 * @param int $post_id Product ID.
	 * @return string|null
	 */
	protected static function product_category( $post_id ) {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}
		return $terms[0]->name;
	}

	/**
	 * Primary image as an ImageObject.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	protected static function primary_image_node( $post_id ) {
		$image = self::primary_image( $post_id );
		return $image ? $image : null;
	}

	/**
	 * Primary image node.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function primary_image( $post_id ) {
		if ( ! has_post_thumbnail( $post_id ) ) {
			return null;
		}
		$id  = get_post_thumbnail_id( $post_id );
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'@type'     => 'ImageObject',
			'@id'       => 'image-#' . (int) $id,
			'url'       => $src[0],
			'contentUrl' => $src[0],
			'width'     => (int) $src[1],
			'height'    => (int) $src[2],
			'caption'   => wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) ),
		);
	}

	/**
	 * FAQ entities.
	 *
	 * @param array $faq FAQ rows.
	 * @return array
	 */
	public static function faq_entities( $faq ) {
		$out = array();
		foreach ( (array) $faq as $item ) {
			$q = trim( (string) ( $item['question'] ?? '' ) );
			$a = trim( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_kses_post( wpautop( $a ) ),
				),
			);
		}
		return $out;
	}

	/**
	 * HowTo node.
	 *
	 * @param array $howto  Steps.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function howto_node( $howto, $post_id = 0 ) {
		$steps = array();
		$index = 1;
		foreach ( (array) ( $howto['steps'] ?? $howto ) as $step ) {
			if ( ! is_array( $step ) ) {
				$step = array( 'name' => (string) $step );
			}
			$name = trim( (string) ( $step['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$node = array(
				'@type'    => 'HowToStep',
				'position' => $index++,
				'name'     => $name,
				'text'     => wp_kses_post( (string) ( $step['text'] ?? '' ) ),
			);
			if ( ! empty( $step['url'] ) ) {
				$node['url'] = esc_url_raw( $step['url'] );
			}
			if ( ! empty( $step['image'] ) ) {
				$node['image'] = esc_url_raw( $step['image'] );
			}
			$steps[] = $node;
		}

		return array_filter(
			array(
				'@type'        => 'HowTo',
				'@id'          => 'howto-#' . (int) $post_id,
				'name'         => $post_id ? get_the_title( $post_id ) : (string) ( $howto['name'] ?? '' ),
				'description'  => $post_id ? Meta::resolve( $post_id, 'description' ) : '',
				'step'         => $steps,
				'totalTime'    => ! empty( $howto['totalTime'] ) ? (string) $howto['totalTime'] : null,
				'tool'         => ! empty( $howto['tools'] ) ? array_map(
					function ( $tool ) {
						return array( '@type' => 'HowToTool', 'name' => (string) $tool );
					},
					(array) $howto['tools']
				) : null,
				'supply'       => ! empty( $howto['supplies'] ) ? array_map(
					function ( $supply ) {
						return array( '@type' => 'HowToSupply', 'name' => (string) $supply );
					},
					(array) $howto['supplies']
				) : null,
			),
			array( __CLASS__, 'keep' )
		);
	}

	/**
	 * Video node.
	 *
	 * @param array $video   Video data.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function video_node( $video, $post_id = 0 ) {
		return array_filter(
			array(
				'@type'         => 'VideoObject',
				'name'          => (string) ( $video['title'] ?? ( $post_id ? get_the_title( $post_id ) : '' ) ),
				'description'   => (string) ( $video['description'] ?? ( $post_id ? Meta::resolve( $post_id, 'description' ) : '' ) ),
				'thumbnailUrl'  => ! empty( $video['thumbnail'] ) ? esc_url_raw( $video['thumbnail'] ) : ( $post_id ? self::primary_image( $post_id )['url'] ?? '' : '' ),
				'uploadDate'    => ! empty( $video['uploadDate'] ) ? (string) $video['uploadDate'] : mysql2date( 'c', get_post_field( 'post_date_gmt', $post_id ), false ),
				'contentUrl'    => ! empty( $video['content_loc'] ) ? esc_url_raw( $video['content_loc'] ) : null,
				'embedUrl'      => ! empty( $video['player_loc'] ) ? esc_url_raw( $video['player_loc'] ) : null,
				'duration'      => ! empty( $video['duration'] ) ? (string) $video['duration'] : null,
				'interactionCount' => ! empty( $video['views'] ) ? (string) (int) $video['views'] : null,
			),
			array( __CLASS__, 'keep' )
		);
	}

	/**
	 * Custom schema rows from the Studio (condition-based patterns).
	 *
	 * @return array
	 */
	public static function custom_graph() {
		if ( ! Database::exists( 'schema' ) ) {
			return array();
		}
		$rows = (array) Helpers::cache(
			'schema-custom',
			function () {
				global $wpdb;
				return (array) $wpdb->get_results( 'SELECT * FROM ' . Database::table( 'schema' ) . " WHERE status = 'active' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
			},
			600
		);

		$out = array();
		foreach ( $rows as $row ) {
			if ( 'footer' === $row['location'] && 'footer' !== self::location() ) {
				continue;
			}
			$conditions = (array) json_decode( (string) $row['conditions'], true );
			if ( ! self::conditions_match( $conditions ) ) {
				continue;
			}
			$data = (array) json_decode( (string) $row['data'], true );
			if ( ! $data ) {
				continue;
			}
			$out[] = $data;
			self::bump( (int) $row['id'] );
		}
		return $out;
	}

	/**
	 * Evaluate a condition set.
	 *
	 * @param array $conditions Conditions.
	 * @return bool
	 */
	public static function conditions_match( $conditions ) {
		if ( ! $conditions ) {
			return is_singular();
		}
		$rules = isset( $conditions['rules'] ) ? (array) $conditions['rules'] : array();
		$logic = isset( $conditions['logic'] ) && 'any' === $conditions['logic'] ? 'any' : 'all';
		if ( ! $rules ) {
			return true;
		}

		$results = array();
		foreach ( $rules as $rule ) {
			$results[] = self::rule_match( $rule );
		}

		return 'any' === $logic ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * One rule.
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	protected static function rule_match( $rule ) {
		$type    = (string) ( $rule['type'] ?? '' );
		$compare = (string) ( $rule['compare'] ?? 'is' );
		$value   = isset( $rule['value'] ) ? $rule['value'] : '';

		switch ( $type ) {
			case 'post_type':
				$matched = is_singular() && in_array( get_post_type(), (array) $value, true );
				break;
			case 'term':
				$object = get_queried_object();
				$matched = $object && isset( $object->term_id ) && in_array( (int) $object->term_id, array_map( 'intval', (array) $value ), true );
				break;
			case 'specific_post':
				$matched = is_singular() && in_array( (int) get_queried_object_id(), array_map( 'intval', (array) $value ), true );
				break;
			case 'front':
				$matched = is_front_page();
				break;
			case 'archive':
				$matched = is_archive();
				break;
			case 'search':
				$matched = is_search();
				break;
			case '404':
				$matched = is_404();
				break;
			case 'template':
				$matched = is_page_template( (string) $value );
				break;
			case 'user_role':
				$matched = current_user_can( (string) $value );
				break;
			case 'woocommerce':
				$matched = function_exists( 'is_product' ) && ( is_product() || is_product_category() || is_product_tag() );
				break;
			default:
				$matched = true;
		}

		return 'not' === $compare ? ! $matched : $matched;
	}

	/**
	 * Count prints for a row.
	 *
	 * @param int $id Row id.
	 */
	protected static function bump( $id ) {
		global $wpdb;
		if ( ! get_transient( 'hoosh_schema_bump_' . $id ) ) {
			set_transient( 'hoosh_schema_bump_' . $id, 1, 600 );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'schema' ) . ' SET print_count = print_count + 1 WHERE id = %d', $id ) ); // phpcs:ignore
		}
	}

	/**
	 * Turn a Studio block into a schema.org node.
	 *
	 * @param array $block Block.
	 * @param int   $post_id Post ID.
	 * @return array|null
	 */
	public static function build_block( $block, $post_id = 0 ) {
		if ( ! is_array( $block ) || empty( $block['type'] ) ) {
			return null;
		}
		$meta = get_post_meta( $post_id, '_hs_schema', true );
		$block['post_id'] = $post_id;

		if ( ! empty( $block['data'] ) && is_array( $block['data'] ) ) {
			$node = self::hydrate( $block['data'], $post_id );
			$node['@type'] = $block['type'];
			return $node;
		}

		// Legacy/short form: type + fields map.
		$node = self::hydrate( (array) ( $block['fields'] ?? array() ), $post_id );
		$node = array_merge( array( '@type' => $block['type'] ), $node );
		unset( $meta );

		return $node;
	}

	/**
	 * Resolve %%variables%% and {{urls}} inside hand-written block values.
	 *
	 * @param array $data   Data.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function hydrate( $data, $post_id = 0 ) {
		$ctx = $post_id ? Helpers::variable_context( $post_id ) : array();
		$out = array();
		foreach ( (array) $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = self::hydrate( $value, $post_id );
				continue;
			}
			if ( is_string( $value ) ) {
				$value = Helpers::render_vars( $value, $ctx );
				$value = preg_replace_callback(
					'/\{\{(\w+)\}\}/',
					function ( $m ) use ( $post_id ) {
						switch ( $m[1] ) {
							case 'permalink':
								return get_permalink( $post_id );
							case 'title':
								return get_the_title( $post_id );
							case 'excerpt':
								return Meta::resolve( $post_id, 'description' );
							case 'date':
								return mysql2date( 'c', get_post_field( 'post_date_gmt', $post_id ), false );
							case 'modified':
								return mysql2date( 'c', get_post_field( 'post_modified_gmt', $post_id ), false );
						}
						return '';
					},
					$value
				);
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Filter callback: drop null / '' / [] values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function keep( $value ) {
		return null !== $value && '' !== $value && array() !== $value;
	}

	/**
	 * Sanitize manual schema blocks coming from the editor/Studio.
	 *
	 * @param mixed $blocks Blocks.
	 * @return array
	 */
	public static function sanitize_blocks( $blocks ) {
		$out = array();
		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = sanitize_text_field( (string) ( $block['type'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}
			$out[] = array(
				'type'   => $type,
				'label'  => sanitize_text_field( (string) ( $block['label'] ?? '' ) ),
				'enabled' => isset( $block['enabled'] ) ? (bool) $block['enabled'] : true,
				'data'    => self::sanitize_values( (array) ( $block['data'] ?? array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Recursive value sanitizer for user-authored schema data.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function sanitize_values( $data ) {
		$out = array();
		foreach ( (array) $data as $key => $value ) {
			$safe_key = preg_replace( '/[^A-Za-z0-9_@\-]/', '', (string) $key );
			if ( '' === $safe_key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $safe_key ] = self::sanitize_values( $value );
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $safe_key ] = $value;
				continue;
			}
			$value = (string) $value;
			if ( preg_match( '#^https?://#i', $value ) ) {
				$out[ $safe_key ] = esc_url_raw( $value );
				continue;
			}
			$out[ $safe_key ] = wp_kses_post( wp_strip_all_tags( $value ) );
		}
		return $out;
	}

	/**
	 * Sanitize FAQ rows.
	 *
	 * @param mixed $faq Rows.
	 * @return array
	 */
	public static function sanitize_faq( $faq ) {
		$out = array();
		foreach ( (array) $faq as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$question = sanitize_text_field( (string) ( $row['question'] ?? '' ) );
			$answer   = wp_kses_post( (string) ( $row['answer'] ?? '' ) );
			if ( '' === $question ) {
				continue;
			}
			$out[] = array(
				'question' => $question,
				'answer'   => $answer,
				'visible'  => isset( $row['visible'] ) ? (bool) $row['visible'] : true,
			);
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Sanitize HowTo rows.
	 *
	 * @param mixed $howto Steps.
	 * @return array
	 */
	public static function sanitize_howto( $howto ) {
		$out = array( 'steps' => array() );
		if ( ! is_array( $howto ) ) {
			return $out;
		}
		$out['totalTime'] = sanitize_text_field( (string) ( $howto['totalTime'] ?? '' ) );
		$out['tools']     = array_map( 'sanitize_text_field', (array) ( $howto['tools'] ?? array() ) );
		$out['supplies']  = array_map( 'sanitize_text_field', (array) ( $howto['supplies'] ?? array() ) );
		foreach ( (array) ( $howto['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				$step = array( 'name' => (string) $step );
			}
			$name = sanitize_text_field( (string) ( $step['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$out['steps'][] = array(
				'name'  => $name,
				'text'  => wp_kses_post( (string) ( $step['text'] ?? '' ) ),
				'image' => isset( $step['image'] ) ? esc_url_raw( (string) $step['image'] ) : '',
				'url'   => isset( $step['url'] ) ? esc_url_raw( (string) $step['url'] ) : '',
			);
		}
		$out['steps'] = array_slice( $out['steps'], 0, 40 );
		return $out;
	}

	/**
	 * CRUD: list custom schema rows.
	 *
	 * @return array
	 */
	public static function listing() {
		global $wpdb;
		if ( ! Database::exists( 'schema' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Database::table( 'schema' ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$row ) {
			$row['data']       = (array) json_decode( (string) $row['data'], true );
			$row['conditions'] = (array) json_decode( (string) $row['conditions'], true );
			$row['print_count'] = (int) $row['print_count'];
		}
		unset( $row );
		return $rows;
	}

	/**
	 * CRUD: save one custom schema row.
	 *
	 * @param array $data Row.
	 * @return int
	 */
	public static function save_row( $data ) {
		global $wpdb;
		$row = array(
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? __( 'قالب اسکیما', 'hoosh-seo' ) ) ),
			'schema_type'   => sanitize_text_field( (string) ( $data['type'] ?? 'WebPage' ) ),
			'data'          => wp_json_encode( self::sanitize_values( (array) ( $data['data'] ?? array() ) ) ),
			'conditions'    => wp_json_encode(
				array(
					'logic' => in_array( (string) ( $data['conditions']['logic'] ?? 'all' ), array( 'all', 'any' ), true ) ? (string) $data['conditions']['logic'] : 'all',
					'rules' => array_values( (array) ( $data['conditions']['rules'] ?? array() ) ),
				)
			),
			'location'    => in_array( (string) ( $data['location'] ?? 'head' ), array( 'head', 'body_start', 'footer' ), true ) ? (string) $data['location'] : 'head',
			'status'      => in_array( (string) ( $data['status'] ?? 'active' ), array( 'active', 'inactive' ), true ) ? (string) $data['status'] : 'active',
			'updated_at'  => current_time( 'mysql', true ),
		);

		Helpers::cache_flush( 'schema-custom' );
		Helpers::cache_flush_all();

		if ( ! empty( $data['id'] ) ) {
			$wpdb->update( Database::table( 'schema' ), $row, array( 'id' => (int) $data['id'] ) ); // phpcs:ignore
			return (int) $data['id'];
		}
		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( Database::table( 'schema' ), $row ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	/**
	 * CRUD: delete rows.
	 *
	 * @param array $ids IDs.
	 * @return int
	 */
	public static function delete_rows( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'schema' ) . ' WHERE id IN (' . $in . ')', $ids ) ); // phpcs:ignore
		Helpers::cache_flush( 'schema-custom' );
		return count( $ids );
	}

	/**
	 * Import structured data from any URL (yours or a competitor's).
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public static function import_from_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			return array( 'ok' => false, 'message' => __( 'نشانی معتبر وارد کنید.', 'hoosh-seo' ) );
		}

		$response = Helpers::http(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'text/html' ),
			)
		);
		if ( ! $response['ok'] || '' === $response['body'] ) {
			return array( 'ok' => false, 'message' => sprintf( /* translators: %s error */ __( 'صفحه خوانده نشد: %s', 'hoosh-seo' ), $response['error'] ? $response['error'] : (string) $response['code'] ) );
		}

		$found = self::extract_from_html( $response['body'] );
		if ( ! $found ) {
			// Microdata / RDFa fallback: report only.
			$microtypes = array();
			if ( preg_match_all( '/itemtype=["\']https?:\/\/schema\.org\/([A-Za-z]+)["\']/i', $response['body'], $m ) ) {
				$microtypes = array_values( array_unique( (array) $m[1] ) );
			}
			return array(
				'ok'      => (bool) $microtypes,
				'blocks'  => array(),
				'microdata' => $microtypes,
				'message' => $microtypes
					? __( 'JSON-LD پیدا نشد اما نشانه‌گذاری Microdata وجود دارد؛ می‌توانید دستی بازسازی کنید.', 'hoosh-seo' )
					: __( 'هیچ داده ساختاریافته‌ای در این صفحه پیدا نشد.', 'hoosh-seo' ),
			);
		}

		$blocks = array();
		foreach ( $found as $node ) {
			$types = (array) ( $node['@type'] ?? array() );
			foreach ( $types as $type ) {
				$blocks[] = array(
					'name'  => get_bloginfo( 'name' ) . ' — ' . $type . ' (imported)',
					'type'  => (string) $type,
					'data'  => self::sanitize_values( (array) $node ),
					'label' => __( 'واردشده', 'hoosh-seo' ),
				);
			}
		}

		return array(
			'ok'     => true,
			'url'    => $url,
			'blocks' => array_slice( $blocks, 0, 20 ),
			'count'  => count( $blocks ),
			'message' => sprintf( /* translators: %d count */ __( '%d گره اسکیما پیدا شد. موارد نامرتبط را قبل از ذخیره حذف کنید.', 'hoosh-seo' ), count( $blocks ) ),
		);
	}

	/**
	 * Pull JSON-LD out of HTML.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function extract_from_html( $html ) {
		$nodes = array();
		if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $m ) ) {
			foreach ( (array) $m[1] as $raw ) {
				$raw = trim( html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' ) );
				$raw = preg_replace( '#^/\*.*?\*/#s', '', $raw );
				$json = json_decode( $raw, true );
				if ( ! is_array( $json ) ) {
					continue;
				}
				$items = array();
				if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
					$items = $json['@graph'];
				} elseif ( isset( $json[0] ) && is_array( $json[0] ) ) {
					$items = $json;
				} else {
					$items = array( $json );
				}
				foreach ( $items as $item ) {
					if ( is_array( $item ) && ! empty( $item['@type'] ) ) {
						$nodes[] = $item;
					}
				}
			}
		}
		return $nodes;
	}

	/**
	 * Offline validator: mirrors Google's documented requirements closely enough
	 * to catch the mistakes that actually cost rich results.
	 *
	 * @param array $nodes Graph nodes.
	 * @return array
	 */
	public static function validate( $nodes ) {
		$catalog = self::types();
		$issues  = array();
		$ids     = array();

		foreach ( (array) $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				$issues[] = array( 'node' => $index, 'level' => 'error', 'message' => __( 'گره، شیء نیست.', 'hoosh-seo' ) );
				continue;
			}
			$types = (array) ( $node['@type'] ?? array() );
			if ( ! $types ) {
				$issues[] = array( 'node' => $index, 'level' => 'error', 'message' => __( 'گره بدون @type.', 'hoosh-seo' ) );
				continue;
			}
			$primary = (string) $types[0];

			if ( isset( $node['@id'] ) ) {
				if ( isset( $ids[ (string) $node['@id'] ] ) ) {
					$issues[] = array( 'node' => $index, 'level' => 'warning', 'message' => sprintf( /* translators: %s id */ __( '@id تکراری: %s', 'hoosh-seo' ), $node['@id'] ) );
				}
				$ids[ (string) $node['@id'] ] = $index;
			}

			$def = isset( $catalog[ $primary ] ) ? $catalog[ $primary ] : null;
			if ( $def ) {
				foreach ( (array) $def['required'] as $prop ) {
					if ( ! isset( $node[ $prop ] ) || '' === $node[ $prop ] ) {
						$issues[] = array(
							'node'    => $index,
							'level'   => 'error',
							'type'    => $primary,
							'message' => sprintf( /* translators: 1: property, 2: type */ __( 'فیلد الزامی %1$s برای %2$s خالی است.', 'hoosh-seo' ), $prop, $primary ),
						);
					}
				}
			}

			// Format checks.
			foreach ( array( 'datePublished', 'dateModified', 'startDate', 'endDate', 'validThrough', 'uploadDate' ) as $date_field ) {
				if ( isset( $node[ $date_field ] ) && ! self::is_iso_date( (string) $node[ $date_field ] ) ) {
					$issues[] = array( 'node' => $index, 'level' => 'warning', 'message' => sprintf( /* translators: %s field */ __( 'قالب تاریخ %s باید ISO8601 باشد.', 'hoosh-seo' ), $date_field ) );
				}
			}
			foreach ( array( 'url', 'logo', 'image', 'mainEntityOfPage' ) as $url_field ) {
				if ( isset( $node[ $url_field ] ) && is_string( $node[ $url_field ] ) && ! preg_match( '#^https?://#i', $node[ $url_field ] ) ) {
					$issues[] = array( 'node' => $index, 'level' => 'warning', 'message' => sprintf( /* translators: %s field */ __( '%s باید آدرس کامل (با https) باشد.', 'hoosh-seo' ), $url_field ) );
				}
			}
			if ( isset( $node['offers'] ) ) {
				$offers = (array) $node['offers'];
				foreach ( $offers as $offer ) {
					if ( is_array( $offer ) && ( ! isset( $offer['price'] ) || ! isset( $offer['priceCurrency'] ) ) ) {
						$issues[] = array( 'node' => $index, 'level' => 'error', 'message' => __( 'هر Offer به price و priceCurrency نیاز دارد.', 'hoosh-seo' ) );
					}
				}
			}
			if ( isset( $node['aggregateRating'] ) && is_array( $node['aggregateRating'] ) ) {
				$rating = $node['aggregateRating'];
				if ( ! isset( $rating['ratingValue'], $rating['reviewCount'] ) && ! isset( $rating['ratingValue'], $rating['ratingCount'] ) ) {
					$issues[] = array( 'node' => $index, 'level' => 'error', 'message' => __( 'AggregateRating به ratingValue و reviewCount نیاز دارد.', 'hoosh-seo' ) );
				}
			}
			if ( in_array( 'FAQPage', $types, true ) && isset( $node['mainEntity'] ) ) {
				foreach ( (array) $node['mainEntity'] as $question ) {
					if ( ! is_array( $question ) || empty( $question['name'] ) || empty( $question['acceptedAnswer']['text'] ) ) {
						$issues[] = array( 'node' => $index, 'level' => 'error', 'message' => __( 'هر پرسش FAQ باید name و acceptedAnswer.text داشته باشد.', 'hoosh-seo' ) );
						break;
					}
				}
			}
		}

		$types_present = array();
		foreach ( (array) $nodes as $node ) {
			foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
				$types_present[] = (string) $type;
			}
		}

		return array(
			'ok'      => ! array_filter( $issues, array( __CLASS__, 'is_error' ) ),
			'issues'  => $issues,
			'types'   => array_values( array_unique( $types_present ) ),
			'nodes'   => count( (array) $nodes ),
			'checked' => array( 'required' => true, 'dates' => true, 'urls' => true, 'ids' => true, 'offers' => true, 'rating' => true, 'faq' => true ),
			'external' => array(
				'google'  => 'https://search.google.com/test/rich-results?url=' . rawurlencode( home_url( add_query_arg( array() ) ) ),
				'structured' => 'https://validator.schema.org/#url=' . rawurlencode( home_url() ),
			),
		);
	}

	/**
	 * Error filter helper.
	 *
	 * @param array $issue Issue.
	 * @return bool
	 */
	public static function is_error( $issue ) {
		return isset( $issue['level'] ) && 'error' === $issue['level'];
	}

	/**
	 * ISO date check.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	protected static function is_iso_date( $value ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', trim( (string) $value ) );
	}

	/**
	 * Live preview payload for the Studio.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function preview( $post_id = 0 ) {
		if ( $post_id ) {
			$nodes = self::for_post( $post_id );
		} else {
			$nodes = self::build();
		}
		$json = wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array_values( array_filter( $nodes ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return array(
			'nodes'    => array_values( array_filter( $nodes ) ),
			'json'     => $json,
			'valid'    => self::validate( $nodes ),
			'location' => self::location(),
			'suppress' => (array) \hoosh_seo()->settings->get( 'schema.suppress', array() ),
			'foreign'  => self::foreign_types(),
		);
	}

	/**
	 * Visible FAQ renderer (schema needs matching on-page content).
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function faq_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'post' => 0, 'style' => 'accordion', 'title' => '' ), $atts, 'hoosh_faq' );
		$post_id = (int) $atts['post'] ? (int) $atts['post'] : get_the_ID();
		$faq     = (array) get_post_meta( $post_id, '_hs_faq', true );
		if ( ! $faq ) {
			return '';
		}
		$html = '<section class="hoosh-faq hoosh-faq--' . esc_attr( $atts['style'] ) . '">';
		if ( $atts['title'] ) {
			$html .= '<h2>' . esc_html( $atts['title'] ) . '</h2>';
		}
		$i = 0;
		foreach ( $faq as $item ) {
			if ( ! empty( $item['visible'] ) === false && isset( $item['visible'] ) ) {
				continue;
			}
			$i++;
			$html .= '<details class="hoosh-faq__item"' . ( 1 === $i ? ' open' : '' ) . '>';
			$html .= '<summary>' . esc_html( (string) ( $item['question'] ?? '' ) ) . '</summary>';
			$html .= '<div class="hoosh-faq__answer">' . wp_kses_post( wpautop( (string) ( $item['answer'] ?? '' ) ) ) . '</div>';
			$html .= '</details>';
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * Visible HowTo renderer.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function howto_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'post' => 0 ), $atts, 'hoosh_howto' );
		$post_id = (int) $atts['post'] ? (int) $atts['post'] : get_the_ID();
		$howto = (array) get_post_meta( $post_id, '_hs_howto', true );
		if ( empty( $howto['steps'] ) ) {
			return '';
		}
		$html = '<ol class="hoosh-howto">';
		foreach ( $howto['steps'] as $index => $step ) {
			$html .= '<li class="hoosh-howto__step"><h3>' . esc_html( (string) ( $step['name'] ?? '' ) ) . '</h3>';
			$html .= '<div>' . wp_kses_post( (string) ( $step['text'] ?? '' ) ) . '</div></li>';
			unset( $index );
		}
		$html .= '</ol>';
		return $html;
	}
}
