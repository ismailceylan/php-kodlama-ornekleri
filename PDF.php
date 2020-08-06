<?php
if( ! defined( 'BASEPATH' )) exit( 'No direct script access allowed' );

/**
 * Sistemin PDF oluşturmaktan sorumlu arayüzüdür.
 *
 * @package     Excel
 * @author      İsmail Ceylan
 * @copyright   Copyright (c) 2012 - İsmail Ceylan
 * @link        http://ismailceylan.com.tr/projeler/hasta-takip-form-olusturma-servisi
 * @license     http://ismailceylan.com.tr/sozlesmeler/3-uncu-taraf
 * @version     1.0.0
 * @since       1.0.0
 */

// -------------------------------------------------------------------------------

class PDF
{
	/**
	 * Bazı özel değişkenler.
	 *
	 * @access private
	 * @var 
	 */
	private $writable  = 'writable';
	private $html      = '';
	private $primary_language, $secondary_language;
	private $ci, $mPDF;
	private $user_id, $project_id;
	private $project, $properties;

	/**
	 * Bazı genel değişkenler.
	 *
	 * @access private
	 * @var 
	 */
	// public 

	/**
	 * Kurucu işlev.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function __construct( $options )
	{
		// büyük projeler üretirken ram sınırı taşabiliyor bunu sadece bu işlem için sınırsız hale
		// getirelim
		ini_set( 'memory_limit', '-1' );

		// codeigniter süper sınıf örneğini bu sınıf için bir defalık örnekleyelim
		$this->ci =& get_instance();

		// sınıfın dışarıdan gönderilen konfigürasyonunu ayrıştıralım
		list( $this->project_id ) = $options;

		// ilgili projenin tam yolunu oluşturmaya çalışalım
		$this->user_id = $this->ci->ion_auth->get_user_id();
		$project_file = $this->writable . '/project/' . $this->user_id . '/' . $this->project_id . '/project.srz';

		// şimdi sistemin konuşabilmesi için ilgili dil dosyalarını yükleyelim
		$this->ci->language->load( 'public' );
		$this->ci->load->model( 'public/project_model' );

		// projenin meta bilgilerini veritabanından okuyalım
		$this->properties = $this->ci->project_model->get_by_path( $this->project_id );

		// dosya ilgili dizinde mevcut mu kontrol edelim
		if( ! file_exists( $project_file ))

			throw new Exception( lang( 'project file not exists' ));

		// dosya mevcut olduğuna göre veriyi runtime içine import edelim
		if( ! $this->project = unserialize( file_get_contents( $project_file )))
			
			throw new Exception( lang( 'project file is damaged' ));

		// proje dosyası mevcut ve okuma işlemi başarılı olduğuna göre hasta açısından
		// birincil ve ikincil dilleri ayarlayalım
		if( $this->properties->patient_lang == $this->properties->doc_lang )
		{
			$this->primary_language = $this->ci->language->id( $this->properties->doc_lang );
			$this->secondary_language = NULL;
		}
		else
		{
			$this->primary_language = $this->ci->language->id( $this->properties->patient_lang );
			$this->secondary_language = $this->ci->language->id( $this->properties->doc_lang );
		}

		$this->project[ 'project_id' ] = $this->project_id;

		// gerekli değişkenler hazır durumda olduğuna göre
		// html oluşturmaya başlayabiliriz
		$this->init();
	}

	/**
	 * Projede seçilen soruları özel biçimlere sahip şablonları kullanarak görünümünü ayarlar.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function init()
	{
		// Gerekli modelleri yükleyelim
		$this->ci->load->model( array
		(
			'admin/sentences_model', 'admin/questions_model', 'admin/categories_model', 'public/project_model'
		));

		// bazı ön değişkenleri hazırlayalım
		$body = '';
		
		$da[ 'branch' ] = $this->ci->categories_model->translate(
			$this->project[ 'branch_slug' ],
			$this->primary_language,
			$this->secondary_language
		);
		
		$project_db = $this->ci->project_model->get_by_path ( $this->project[ 'project_id'  ]);

		$template_params = array
		(
			'project' => $this->project,
			'branch' => $da[ 'branch' ],
			'creator' => $this->properties->creator,
			'ok' => $this->properties->ok,
			'primary_language' => $this->properties->patient_lang,
			'secondary_language' => $this->properties->doc_lang
		);

		$template_params = array_merge(
			$template_params,
			array(
				'color' => $this->ci->project_model->theme( $project_db->theme )
			)
		);

		// formda yer alacak bölgelerin istediğimiz sırada dizilmesi için index oluşturalım
		// ve formun stabil parçalarını bu indexe dahil edelim
		$this->html =        $this->ci->load->view( 'public/project/templates/template', $template_params, TRUE );
		$this->push( 'logo', $this->ci->load->view( 'public/project/templates/logo',     $project_db,      TRUE ));

		foreach( $this->project[ 'questions' ] AS $excel_id => $answers )
		{
			$qu = $this->ci->questions_model->get_by_excel
			(
				str_replace( '-', '.', $excel_id ),
				$this->primary_language,
				$this->secondary_language
			);

			// sağdan başladığını bildiğimiz dillerin sistemdeki idlerini bulalım
			$right_start_languages =
			[
				$this->ci->language->id( 'arabic' ) => 'arabic'
			];

			// birinci ve ikinci dillerin durumlarını ayrı ayrı kontrol edelim
			$primary_is_start_right = array_key_exists( $this->primary_language, $right_start_languages );
			$secondary_is_start_right = array_key_exists( $this->secondary_language, $right_start_languages );

			// dataları hazırlayalım bunlar her template dosyasından erişilebilir olurlar
			$da[ 'primary_language'         ] = $this->primary_language;
			$da[ 'secondary_language'       ] = $this->secondary_language;
			$da[ 'primary_sentence'         ] = $qu->primary_sentence;
			$da[ 'secondary_sentence'       ] = isset( $qu->secondary_sentence )? $qu->secondary_sentence : NULL;
			$da[ 'question_answers'         ] = $answers;
			$da[ 'excel_id'                 ] = $excel_id;
			$da[ 'primary_is_start_right'   ] = $primary_is_start_right;
			$da[ 'secondary_is_start_right' ] = $secondary_is_start_right;

			$template = $this->ci->load->view( 'public/project/templates/' . $qu->format, $da, TRUE );

			switch( $qu->format )
			{
				case 'title'  :
					$this->push( 'title', $template );

					foreach( $answers AS $excel_id => $val )
					{
						$answer = $this->ci->sentences_model->get_by_excel(
							str_replace( '-', '.', $excel_id ),
							$this->primary_language,
							$this->secondary_language
						);
					}
					
					$this->push( 'form-title', @$answer->secondary_sentence );
					break;

				case 'welcome': $this->push( 'welcome', $template ); break;
				case 'goodbye': $this->push( 'goodbye', $template ); break;
				default       : $body .= $template; break;
			}
		}

		// branş için welcome içeriği zorunlu değil o yüzden welcome türünde bir soru hiç gelmeyebilir
		// ancak yer tutucusu şablonda kalıyor bu nedenle burada sırf bu tutucuyu silmek adına null veri
		// göndereceğiz ki zaten branşta welcome varsa hemen yukarıda yerine yazılmıştır buradaki işlemde
		// yer tutucu bulamayacağı zaman hiçbir şey olmaz ama yer tutucu mevcutsa bunu null veri ile silmiş
		// olur
		$this->push( 'welcome', NULL );
		$this->push( 'body', $body );
// echo $this->html;
// exit();
		$this->render();

	}

	/**
	 * HTML index içeriğinin istenen içerik bölümüne gönderilen içeriği yazar.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function push( $segment, $html )
	{
		$this->html = str_replace( '[' . $segment . ']', $html, $this->html );
	}

	/**
	 * HTML kaynak kodunu PDF dökümanına dönüştürür.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function render()
	{
		// PDF oluşturucu kütüphaneyi çağıralım
		include( APPPATH . 'third_party/mPDF/mpdf.php');

		$this->mPDF = new mPDF();

		$this->mPDF->SetAutoFont( AUTOFONT_ALL );
		$this->mPDF->WriteHTML( $this->html  );

		return $this;
	}

	/**
	 * PDF dosyasını browser'ın indirebileceği biçimde kullanıcıya gönderir.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function download()
	{
		// $this->mPDF->Output( $this->project[ 'branch_slug' ] . '.pdf', 'D' );
		
		$this->ci->load->helper( 'download' );

		$path = $this->save();
		$PDF  = file_get_contents( $path );

		force_download( $this->project[ 'branch_slug' ] . '.pdf', $PDF );
	}

	/**
	 * PDF dosyasını browser'ın görüntüleyebileceği biçimde kullanıcıya gönderir. Browser
	 * online pdf görüntüleme eklentisine sahipse dosyayı görüntüleyecektir.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function browser()
	{
		$this->mPDF->Output( $this->project[ 'branch_slug' ] . '.pdf', 'I' );
	}

	/**
	 * PDF dosyası kullanıcının ilgili projesine ait klasöre kaydedilir.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function save()
	{
		$this->mPDF->Output(
			$filename = $this->writable . '/project/' .
						$this->user_id . '' .
						$this->properties->path . '' .
						$this->project[ 'branch_slug' ] . '.pdf',
			'F'
		);

		return $filename;
	}

	/**
	 * PDF dosyasının ikilik verisini geriye döndürür. E-Mail ile pdf göndermek veya veritabanında saklamak
	 * için kullanılabilir.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function binary()
	{
		return $this->mPDF->Output( 'preview.pdf', 'S' );
	}
}
