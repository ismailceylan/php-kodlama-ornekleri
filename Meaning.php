<?php

/**
 * Yazımı aynı olsa bile farklı anlamlara gelen başlıklar vardır. Örneğin "mısır" gibi. Bu
 * tür başlıklara girilen entry'lerin hangi anlamda yazıldığını tespit eder.
 */
class Meanings extends Collection
{
	/**
	 * Sınıf adını tutar. Gerektiğinde sürekli metod kullanmak yerine buna erişilmelidir.
	 * @var String
	 */
	public static $prefix = 'meanings';

	/**
	 * Kurulumu yapar.
	 * 
	 * @param Array $results veritabanı sorgu sonucu (active records dizisi)
	 */
	public function __construct( Array $results = NULL )
	{
		parent::__construct( $results );
	}

	/**
	 * String biçiminde verilen girdi üzerinde temsil edilen anlamların ne oranda eşleştiğini
	 * anlamaya çalışıp en çok uyuşma sağladığı düşünülen bir tane anlamı döndürür.
	 * 
	 * @param  String $script analiz edilecek metin
	 * @return Array
	 */
	public function execute( $script )
	{
		// metindeki işaret karakterlerini silelim
		$script = $this->clearScript( $script );
		// temsil ettiğimiz sözcüklerden bir harita oluşturmalıyız
		$map = [];
		// anlam ayrımlarını ve bunların puan bilgilerini vs tutmak için bir dizi daha lazım
		$board = [];

		// önce tüm anlamları dönelim
		foreach( $this AS $index => $meaning )
		{
			$board[ $meaning->name ] =
			[
				'index' => $index,
				'score' => 0
			];

			// anlamdaki sözcükleri ayırıp hepsini tek tek dönelim
			foreach( explode( ',', $meaning->words ) AS $word )
			{
				$word = $this->clearScript( $word );
				
				// sözcüğü haritaya ilave edelim
				if( ! array_key_exists( $word, $map ))

					$map[ $word ] = [ $meaning->name ];

				else $map[ $word ][] = $meaning->name;
			}
		}

		// şimdi haritadaki sözcükleri metin içinde buldukça sözcüğü temsil eden anlamlara
		// puan bölüştürmesi yapacağız
		foreach( $map AS $word => $meanings )
		{
			// bakalım sözcük metin içinde geçiyor mu, sözcüğün başına
			// boşluk koyalım ki mesela un anahtar kelimesi bir yazıda
			// ek olarak da geçebilir ancak un sözcüğüyle başlayan
			// kelimeler bize gerekli olduğu için başına boşluk koymak bunu sağlar
			// bu arada metnin başına da bir boşluk koymak gerekir çünkü
			// anahtar kelime belki en baştaki sözcükle eşleşecekse bile
			// boşluk yüzünden eşleşemez ve sorun olur ancak metinin başında
			// boşluk olursa bu sorun da ortadan kalkar
			if( strpos( mb_strtolower( ' ' . $script ), ' ' . $word ) > -1 )
			{
				// sözcük metin içinde geçiyor o zaman sözcüğü paylaşan kaç tane anlam ayrımı varsa
				// bütün hepsi 1 tam puanı aralarında eşit paylaşsın
				$puant = 1 / count( $meanings );

				// sözcüğü paylaşan anlam ayrımlarını dönelim
				foreach( $meanings AS $meaning )

					// anlam ayrımının puanını güncelleyebiliriz
					$board[ $meaning ][ 'score' ] += $puant;
			}
		}

		// son aşamada artık hangi anlamın daha ön plana çıktığına bir bakıp ilgili anlam nesnesini
		// geriye döndürerek analiz işlemini bitirelim
		uasort( $board, function( $a, $b )
		{
			if( $a[ 'score' ] < $b[ 'score' ])

				return 1;
		});

		// ilk elemanla ikinci arasında az fark varsa ikisini de dönelim
		// yok çok fark varsa ilkini dönebiliriz
		$finalist = array_slice( $board, 0, 2 );
		$meanings = [];

		if(( $l = count( $finalist )) == 1 )

			$meanings[] = $this->child( array_pop( $finalist )[ 'index' ]);

		else if( $l > 1 )
		{
			$i = 0;

			foreach( $finalist AS $name => $props )
			{
				if( $i == 0 )
				{
					$primary = $name;
					$i++;
				}

				if( $i == 1 )
				{
					$secondary = $name;
				}
			}
			
			$totalScore = $finalist[ $primary ][ 'score' ] + $finalist[ $secondary ][ 'score' ];
			$differance = $finalist[ $primary ][ 'score' ] - $finalist[ $secondary ][ 'score' ];

			if( $totalScore <= 0 )

				$meanings = [];

			// fark belli bir limitten azsa ikisini de döndürelim
			else
			{
				if( $differance / $totalScore * 100 <= 10 )
				{
					$meanings[] = $this->child( $finalist[ $primary   ][ 'index' ]);
					$meanings[] = $this->child( $finalist[ $secondary ][ 'index' ]);
				}
				else

					$meanings[] = $this->child( $finalist[ $primary ][ 'index' ]);
			}
		}

		return new Meanings( $meanings );
	}

	/**
	 * Gönderilen metin içindeki tüm noktalama işaretlerini temizler. Türkçe harfleri
	 * latin karşılığına dönüştürür.
	 * 
	 * @param  String $script temizlenecek entry metni
	 * @return String
	 */
	private function clearScript( $script )
	{
		$script = str_replace
		([
			'{', '}', '(', ')', '[', ']', '!', "'",  '&', '/', '=', '?', '*',
			'"', '^', '#', '+', '$', '%', '½', '\\',  '-', '_', '@', '€', '¨',
			'~', '´', ',', ';', '`', '<', '>', '|', '.', ':'
		], '', $script );

		return str_replace
		(
			[ 'ç', 'ğ', 'ı', 'ö', 'ş', 'ü' ],
			[ 'c', 'g', 'i', 'o', 's', 'u' ],
			$script
		);
	}
}
