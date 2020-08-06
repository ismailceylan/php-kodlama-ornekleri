<?php
if( ! defined( 'BASEPATH' )) exit( 'No direct script access allowed' );

/**
 * Excel ile sisteme topluca soru yüklemesini sağlayan arayüzdür.
 *
 * @package     Excel
 * @author      İsmail Ceylan
 * @copyright   Copyright (c) 2012 - İsmail Ceylan
 * @link        http://ismaillceylan.com.tr/projeler/hasta-takip-form-olusturma-servisi
 * @license     http://ismaillceylan.com.tr/sozlesmeler/3-uncu-taraf
 * @version     1.0.0
 * @since       1.0.0
 */

// -------------------------------------------------------------------------------

class Excel
{
	/**
	 * Bazı özel değişkenler.
	 *
	 * @access private
	 * @var 
	 */
	private $XLS, $system_languages;
	private $upload_path = 'writable/excel/';
	private $meta_offsets = array(),
			$allowed = array( 'xls', 'xlsx' );

	/**
	 * Bazı genel değişkenler.
	 *
	 * @access private
	 * @var 
	 */
	public $cells = array(),
		   $languages = array(),
		   $groups = array(),
		   $questions = array(),
		   $answers = array();

	/**
	 * Kurucu işlev.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		require_once APPPATH . 'third_party/PHPExcel/PHPExcel.php';

		$ci =& get_instance();
		$ci->load->model( 'admin/languages_model' );
		$this->system_languages = $ci->languages_model->all();

		try
		{
			$full_path = $this->upload( $_FILES );
			
			if( $this->read( $full_path ))
			{
				$this->parse();
			}
		}
		catch( Exception $e )
		{
			// algılanan hatayı bir üst düzeye iletelim
			throw new Exception( $e->getMessage( ));
		}
	}

	/**
	 * Excel dökümanını yükleyip yeni dosyanın tam yolunu döndürür.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	private function upload()
	{
		if( $_FILES[ 'document' ][ 'error' ])
		{
			throw new Exception( 'Dosya algılanmadı!' );
		}
		else
		{
			$parts = explode( '.', $_FILES[ 'document' ][ 'name' ]);
			$ext = $parts[ count( $parts ) - 1 ];
			$new_file = md5( microtime( TRUE )) . '.' . $ext;
			$full_path = $this->upload_path . $new_file;

			if( ! in_array( $ext, $this->allowed ))
			{
				throw new Exception(
					sprintf(
						lang( 'excel unallowed file error' ),
						$ext,
						implode( ', ', $this->allowed )
					)
				);

				return;
			}

			move_uploaded_file( $_FILES[ 'document' ][ 'tmp_name' ], $full_path );

			return $full_path;
		}
	}

	/**
	 * Yolu verilen excel dökümanını ayrıştırıp tüm hücrelerini bir diziye yükler.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	private function read( $full_path )
	{
		$this->XLS = PHPExcel_IOFactory::load( $full_path );

		foreach( $this->XLS->getWorksheetIterator() AS $document )
		{
			foreach( $document->getRowIterator() AS $line )
			{
				foreach( $line->getCellIterator() AS $cell )
				{
					$this->cells[] = $cell->getCalculatedValue();
				}
			}
		}

		return TRUE;
	}

	/**
	 * Dizideki tüm hücreleri tek tek işleyip hangi özelliği taşıyorsa o gruba gönderir, gruplandırır.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	private function parse()
	{
		$flag = 0;

		// ilk hücre hiçbir işe yaramıyor olacak, eğer null ise diziden atalım
		if( is_null( $this->cells[ 0 ]))

			array_shift( $this->cells );

		// hangi meta bilgi hangi sıradaysa index'lerini bulalım
		// desteklenecek meta bilgiler burada kod içinde elle tanımlanmalı
		// bu işlem sadece desteklenen meta sütunlarının herhangi bir sırada olmasına imkan verir
		// ilk null ile karşılaştığımızda meta tanımları bitmiş demektir
		// index numaralarını sıfırdan başlatacağız, her satır için en soldan bir adet hücreyi null varsayacağız
		// ancak döngü sırasında bu null yeni satıra geçildiğini haber verip yok olacağından indexler sıfırdan başladı
		// döngü sınırı olarak 100 belirlendi, cell sayısı alınabilirdi ancak performans nedeniyle kullanılmadı
		for( $i = 0; $i < 100; $i++ )
		{
			$c    = NULL;
			$cell = array_shift( $this->cells );

			if( is_null( $cell ))

				break;

			switch( trim( mb_strtolower( $cell )))
			{
				case 'no' : $c = 'id'; break;
				case 'selection': $c = 'requirement'; break;
				case 'table' : $c = 'format'; break;
			}

			if( ! is_null( $c ))
			{
				$this->meta_offsets[ $c ] = $flag;
				$flag++;
			}
		}

		// şimdi 1 adet null hücreyi index listesine ekleyelim çünkü
		// veri içinde görevi yok sadece başlıktaki meta ve dil hücrelerini ayırıyor
		// veri içinde görevi olmadığından pas geçebilmek için o hücreyi de index listesine ilave edeceğiz
		// kendinden sonra gelen tüm indexi bir ileri iteceği için veri okuma sırasında bu kör hücre atlanmış olacak
		$this->meta_offsets[ 'null' ] = $flag;

		// şimdi hücre yığınında dillerin ingilizce yazımları kalmış olmalı
		// isimlerini ve sıralarını kaydedelim ancak indekslerini tutarken kendilerinden sonra
		// ilk karşılaşacağımız null, dillerin bittiğini, yeni satıra geçtiğimizi bildirir
		for( $i = 0; $i < 100; $i++ )
		{
			$cell = array_shift( $this->cells );

			if( is_null( $cell ))

				break;

			$this->meta_offsets[ $cell ] = $flag + 1;
			$this->languages[] = $cell;

			$flag++;
		}

		// hücre yığınından dilleri de aldığımıza göre artık elimizde sadece veriler kaldı
		// şimdi tek tek elimizdeki index matrisine bakarak hücrelerin rolleri tespit edilecek ve
		// ilgili diziye ekleyeceğiz
		for( $i = 0, $l = count( $this->cells ), $m = count( $this->meta_offsets ) + 1; $i < $l; $i += $m )
		{
			$t = array( 'sentences' => array( ));
			$mode = 'add meta';

			foreach( $this->meta_offsets AS $cell_name => $cell_position )
			{
				if( $cell_name == 'null' )
				{
					$mode = 'add sentence';
					continue;
				}
				
				$c = $this->cells[ $i + $cell_position ];

				if( $cell_name == 'format' )
				{
					if( $c == NULL )

						$c = 'check';

					$c = str_replace( ' ', '_', $c );
				}

				if( $mode == 'add meta' )
				{
					$t[ $cell_name ] = $c;
				}
				elseif( $mode == 'add sentence' )
				{
					$t[ 'sentences' ][ $this->short_code( $cell_name )] = $c;
				}
			}

			$IDs = $this->find_state( $t[ 'id' ]);
			$state = array_shift( $IDs );
			$t[ 'id' ] = $IDs;

			switch( $state )
			{
				case 'group'   : $this->groups[]    = $t; break;
				case 'question': $this->questions[] = $t; break;
				case 'answer'  : $this->answers[]   = $t; break;
			}
		}
	}

	/**
	 * Verilen döküman içi numaralandırma bilgisini analiz ederek ilgili satırın bir grup mu, soru mu yoksa
	 * yanıt mı olduğu bilgisini döndürür.
	 *
	 * @author Ismail Ceylan
	 * @param  String $identation
	 * @access public
	 * @return void
	 */
	private function find_state( $identation )
	{
		$p = explode( '.', trim( trim( $identation ), '., ' ));

		if( ! array_key_exists( 0, $p ))
		{
			return FALSE;
		}
		elseif( ! array_key_exists( 1, $p ))
		{
			return array(
				'group',
				'raw' => $identation,
				'group' => $p[ 0 ]
			);
		}
		elseif( ! array_key_exists( 2, $p ))
		{
			return array(
				'question',
				'raw' => $identation,
				'group' => $p[ 0 ],
				'question' => $p[ 1 ]
			);
		}
		elseif( ! array_key_exists( 3, $p ))
		{
			return array(
				'answer',
				'raw' => $identation,
				'group' => $p[ 0 ],
				'question' => $p[ 1 ],
				'answer' => $p[ 2 ]
			);
		}
	}

	/**
	 * Bir dilin ingilizce yazımını sistemdeki kayıtlı dillerin ingilizce yazımlarında arar, id döndürür.
	 *
	 * @author Ismail Ceylan
	 * @param  String $language
	 * @access public
	 * @return String
	 */
	public function short_code( $language )
	{
		foreach( $this->system_languages AS $lang )
		{
			if( $lang[ 'en' ] == $language )

				return $lang[ 'id' ];
		}
	}

	/**
	 * Dosyadan istenen veriyi gönderilen fonksiyonla tek tek test eder.
	 *
	 * @author Ismail Ceylan
	 * @access public
	 * @return void
	 */
	public function map( $segment, $callback )
	{
		if( $segment == 'groups' )
		{
			foreach( $this->groups AS $group )
			{
				$callback(
					$group[ 'id' ][ 'raw' ],
					$group[ 'sentences' ]
				);
			}
		}
		elseif( $segment == 'questions' )
		{
			foreach( $this->questions AS $question )
			{
				$callback(
					$question[ 'id' ][ 'raw' ],
					$question[ 'id' ][ 'group' ],
					$question[ 'id' ][ 'question' ],
					$question[ 'sentences' ],
					$question[ 'format' ],
					$question[ 'requirement' ]
				);
			}
		}
		elseif( $segment == 'answers' )
		{
			foreach( $this->answers AS $answer )
			{
				$callback(
					$answer[ 'id' ][ 'group' ],
					$answer[ 'id' ][ 'question' ],
					$answer[ 'id' ][ 'answer' ],
					$answer[ 'sentences' ],
					$answer[ 'requirement' ]
				);
			}
		}

		return $this;
	}
}
