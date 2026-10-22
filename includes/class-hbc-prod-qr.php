<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compact QR (version 4, ECC L) for short-lived encounter URLs. No third-party image host.
 */
final class HBC_Prod_QR {
	public static function svg( string $text ): string {
		$matrix = self::matrix( $text );
		$n      = count( $matrix );
		$mod    = 8;
		$quiet  = 4;
		$size   = ( $n + $quiet * 2 ) * $mod;
		$out    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">';
		$out   .= '<rect width="100%" height="100%" fill="#fff"/>';
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $matrix[ $y ][ $x ] ) {
					$out .= '<rect x="' . ( ( $x + $quiet ) * $mod ) . '" y="' . ( ( $y + $quiet ) * $mod ) . '" width="' . $mod . '" height="' . $mod . '" fill="#0b1f33"/>';
				}
			}
		}
		return $out . '</svg>';
	}

	private static function matrix( string $text ): array {
		$ver  = 4;
		$size = 17 + 4 * $ver;
		$data = self::encode_bytes( $text, $ver );
		$grid = array_fill( 0, $size, array_fill( 0, $size, null ) );
		self::place_finders( $grid, $size );
		self::place_timing( $grid, $size );
		self::place_align( $grid );
		self::place_dark( $grid, $ver );
		self::reserve_format( $grid, $size );
		self::place_data( $grid, $size, $data );
		return self::best_mask( $grid, $size );
	}

	private static function encode_bytes( string $text, int $ver ): array {
		$bytes = array_values( unpack( 'C*', $text ) );
		$bits  = array();
		self::push( $bits, 4, 0x04 );
		self::push( $bits, 8, count( $bytes ) );
		foreach ( $bytes as $b ) {
			self::push( $bits, 8, $b );
		}
		$codewords = $ver === 4 ? 80 : 55;
		$ec        = $ver === 4 ? 20 : 15;
		while ( count( $bits ) + 4 <= $codewords * 8 && count( $bits ) < $codewords * 8 ) {
			self::push( $bits, 4, 0 );
		}
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		$pad = 0xEC;
		$data = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$v = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$v = ( $v << 1 ) | ( $bits[ $i + $j ] ?? 0 );
			}
			$data[] = $v;
		}
		while ( count( $data ) < $codewords ) {
			$data[] = $pad;
			$pad    = $pad === 0xEC ? 0x11 : 0xEC;
		}
		$data = array_slice( $data, 0, $codewords );
		$ecc  = self::rs( $data, $ec );
		return array_merge( $data, $ecc );
	}

	private static function push( array &$bits, int $len, int $value ): void {
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	private static function rs( array $data, int $ec_len ): array {
		$gen = self::rs_generator( $ec_len );
		$ecc = array_fill( 0, $ec_len, 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ $ecc[0];
			array_shift( $ecc );
			$ecc[] = 0;
			if ( $factor === 0 ) {
				continue;
			}
			for ( $i = 0; $i < $ec_len; $i++ ) {
				$ecc[ $i ] ^= self::gf_mul( $gen[ $i ], $factor );
			}
		}
		return $ecc;
	}

	private static function rs_generator( int $deg ): array {
		$poly = array( 1 );
		for ( $i = 0; $i < $deg; $i++ ) {
			$next = array_fill( 0, count( $poly ) + 1, 0 );
			$c    = self::gf_exp( $i );
			for ( $j = 0; $j < count( $poly ); $j++ ) {
				$next[ $j ]    ^= $poly[ $j ];
				$next[ $j + 1 ] ^= self::gf_mul( $poly[ $j ], $c );
			}
			$poly = $next;
		}
		array_shift( $poly );
		return $poly;
	}

	private static function gf_exp( int $n ): int {
		$x = 1;
		for ( $i = 0; $i < $n; $i++ ) {
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11d;
			}
		}
		return $x;
	}

	private static function gf_mul( int $a, int $b ): int {
		$p = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			if ( $b & 1 ) {
				$p ^= $a;
			}
			$hi = $a & 0x80;
			$a  = ( $a << 1 ) & 0xff;
			if ( $hi ) {
				$a ^= 0x1d;
			}
			$b >>= 1;
		}
		return $p;
	}

	private static function place_finders( array &$g, int $size ): void {
		foreach ( array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) ) as $p ) {
			for ( $y = -1; $y <= 7; $y++ ) {
				for ( $x = -1; $x <= 7; $x++ ) {
					$yy = $p[1] + $y;
					$xx = $p[0] + $x;
					if ( $yy < 0 || $xx < 0 || $yy >= $size || $xx >= $size ) {
						continue;
					}
					$on = ( $x >= 0 && $x <= 6 && $y >= 0 && $y <= 6 ) && ( $x === 0 || $x === 6 || $y === 0 || $y === 6 || ( $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4 ) );
					$g[ $yy ][ $xx ] = $on;
				}
			}
		}
	}

	private static function place_timing( array &$g, int $size ): void {
		for ( $i = 8; $i < $size - 8; $i++ ) {
			if ( $g[6][ $i ] === null ) {
				$g[6][ $i ] = ( $i % 2 ) === 0;
			}
			if ( $g[ $i ][6] === null ) {
				$g[ $i ][6] = ( $i % 2 ) === 0;
			}
		}
	}

	private static function place_align( array &$g ): void {
		$c = 26;
		for ( $y = $c - 2; $y <= $c + 2; $y++ ) {
			for ( $x = $c - 2; $x <= $c + 2; $x++ ) {
				$g[ $y ][ $x ] = ( $x === $c - 2 || $x === $c + 2 || $y === $c - 2 || $y === $c + 2 || ( $x === $c && $y === $c ) );
			}
		}
	}

	private static function place_dark( array &$g, int $ver ): void {
		$g[ ( 4 * $ver ) + 9 ][8] = true;
	}

	private static function reserve_format( array &$g, int $size ): void {
		for ( $i = 0; $i < 9; $i++ ) {
			if ( $g[8][ $i ] === null ) {
				$g[8][ $i ] = false;
			}
			if ( $g[ $i ][8] === null ) {
				$g[ $i ][8] = false;
			}
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$g[8][ $size - 1 - $i ] = false;
			$g[ $size - 1 - $i ][8] = false;
		}
	}

	private static function place_data( array &$g, int $size, array $codewords ): void {
		$bits = array();
		foreach ( $codewords as $cw ) {
			for ( $i = 7; $i >= 0; $i-- ) {
				$bits[] = ( $cw >> $i ) & 1;
			}
		}
		$idx  = 0;
		$up   = true;
		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( $col === 6 ) {
				$col--;
			}
			for ( $n = 0; $n < $size; $n++ ) {
				$row = $up ? $size - 1 - $n : $n;
				for ( $c = 0; $c < 2; $c++ ) {
					$x = $col - $c;
					if ( $g[ $row ][ $x ] !== null ) {
						continue;
					}
					$g[ $row ][ $x ] = ! empty( $bits[ $idx ] );
					$idx++;
				}
			}
			$up = ! $up;
		}
	}

	private static function best_mask( array $grid, int $size ): array {
		$best = null;
		$best_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$cand = self::apply_mask( $grid, $size, $mask );
			self::write_format( $cand, $size, $mask );
			$score = self::score( $cand, $size );
			if ( $score < $best_score ) {
				$best_score = $score;
				$best       = $cand;
			}
		}
		return $best;
	}

	private static function apply_mask( array $grid, int $size, int $mask ): array {
		$out = $grid;
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( self::is_function( $grid, $size, $x, $y ) ) {
					continue;
				}
				if ( self::mask_bit( $mask, $x, $y ) ) {
					$out[ $y ][ $x ] = ! $out[ $y ][ $x ];
				}
			}
		}
		return $out;
	}

	private static function is_function( array $g, int $size, int $x, int $y ): bool {
		if ( $y === 6 || $x === 6 ) {
			return true;
		}
		if ( $x < 9 && $y < 9 ) {
			return true;
		}
		if ( $x >= $size - 8 && $y < 9 ) {
			return true;
		}
		if ( $x < 9 && $y >= $size - 8 ) {
			return true;
		}
		if ( $x >= 24 && $x <= 28 && $y >= 24 && $y <= 28 ) {
			return true;
		}
		return false;
	}

	private static function mask_bit( int $mask, int $x, int $y ): bool {
		switch ( $mask ) {
			case 0:
				return ( ( $x + $y ) % 2 ) === 0;
			case 1:
				return ( $y % 2 ) === 0;
			case 2:
				return ( $x % 3 ) === 0;
			case 3:
				return ( ( $x + $y ) % 3 ) === 0;
			case 4:
				return ( ( (int) floor( $y / 2 ) + (int) floor( $x / 3 ) ) % 2 ) === 0;
			case 5:
				return ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 ) === 0;
			case 6:
				return ( ( ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2 ) === 0;
			default:
				return ( ( ( ( $x + $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2 ) === 0;
		}
	}

	private static function write_format( array &$g, int $size, int $mask ): void {
		$bits = self::format_bits( $mask );
		$map  = array(
			array( 0, 8 ), array( 1, 8 ), array( 2, 8 ), array( 3, 8 ), array( 4, 8 ), array( 5, 8 ), array( 7, 8 ), array( 8, 8 ),
			array( 8, 7 ), array( 8, 5 ), array( 8, 4 ), array( 8, 3 ), array( 8, 2 ), array( 8, 1 ), array( 8, 0 ),
		);
		$map2 = array(
			array( 8, $size - 1 ), array( 8, $size - 2 ), array( 8, $size - 3 ), array( 8, $size - 4 ), array( 8, $size - 5 ), array( 8, $size - 6 ), array( 8, $size - 7 ),
			array( $size - 8, 8 ), array( $size - 7, 8 ), array( $size - 6, 8 ), array( $size - 5, 8 ), array( $size - 4, 8 ), array( $size - 3, 8 ), array( $size - 2, 8 ), array( $size - 1, 8 ),
		);
		for ( $i = 0; $i < 15; $i++ ) {
			$bit = (bool) $bits[ $i ];
			$g[ $map[ $i ][1] ][ $map[ $i ][0] ]   = $bit;
			$g[ $map2[ $i ][1] ][ $map2[ $i ][0] ] = $bit;
		}
	}

	private static function format_bits( int $mask ): array {
		$data = ( 1 << 3 ) | $mask;
		$bits = $data << 10;
		$gen  = 0x537;
		for ( $i = 14; $i >= 10; $i-- ) {
			if ( $bits & ( 1 << $i ) ) {
				$bits ^= $gen << ( $i - 10 );
			}
		}
		$val = ( $data << 10 | $bits ) ^ 0x5412;
		$out = array();
		for ( $i = 14; $i >= 0; $i-- ) {
			$out[] = ( $val >> $i ) & 1;
		}
		return $out;
	}

	private static function score( array $g, int $size ): int {
		$score = 0;
		for ( $y = 0; $y < $size; $y++ ) {
			$run = 1;
			for ( $x = 1; $x < $size; $x++ ) {
				if ( $g[ $y ][ $x ] === $g[ $y ][ $x - 1 ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$score += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$score += 3 + ( $run - 5 );
			}
		}
		return $score;
	}
}
