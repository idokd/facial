<?php
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
//
// @Author Karthik Tharavaad
//         karthik_tharavaad@yahoo.com
// @Contributor Maurice Svay
//              maurice@svay.Com
// @Contributor Marijn Ophorst
//              marijn@sensimedia.nl

namespace Sensi\Facial;

use Exception;
use DomainException;
use GdImage;

class Detectable
{
    /** @var array */
    protected array $detection_data;

    /** @var resource */
    protected $canvas;

    /** @var array|null */
    protected ?array $face;

    /**
     * Creates a face-detector with the given configuration
     *
     * Configuration can be either passed as an array or as
     * a filepath to a serialized array file-dump
     *
     * @param array $detection_data
     * @param resource|GdImage $canvas
     * @return void
     * @throws Exception
     */
    public function __construct(array $detection_data, $canvas)
    {
        if (PHP_VERSION_ID < 80000 && !is_resource($canvas)) {
            throw new DomainException("Canvas must be passed as a resource");
        }
        if (PHP_VERSION_ID >= 80000 && !($canvas instanceof GdImage)) {
            throw new DomainException("Canvas must be passed as an instance of GdImage");
        }
        $this->detection_data = $detection_data;
        $this->canvas = $canvas;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function detectFace() : bool
    {
        if (!isset($this->canvas)) {
            throw new Exception("Canvas not set! Initialize with one of the fromXXX methods");
        }

        $im_width = imagesx($this->canvas);
        $im_height = imagesy($this->canvas);

        // Resample before detection?
        $diff_width = 320 - $im_width;
        $diff_height = 240 - $im_height;
        if ($diff_width > $diff_height) {
            $ratio = $im_width / 320;
        } else {
            $ratio = $im_height / 240;
        }

        if ($ratio != 0) {
            $canvas = imagecreatetruecolor(round($im_width / $ratio), round($im_height / $ratio));
            imagecopyresampled(
                $canvas,
                $this->canvas,
                0,
                0,
                0,
                0,
                round($im_width / $ratio),
                round($im_height / $ratio),
                $im_width,
                $im_height
            );
        } else {
            $canvas = $this->canvas;
        }
        $stats = new ImageStats($canvas);
        $this->face = $this->doDetectGreedyBigToSmall(
            $stats->ii,
            $stats->ii2,
            $stats->width,
            $stats->height
        );
        if (!$this->face) {
            return false;
        }
        if ($this->face['w'] > 0) {
            $this->face['x'] *= $ratio;
            $this->face['y'] *= $ratio;
            $this->face['w'] *= $ratio;
        }
        return ($this->face['w'] > 0);
    }

    /**
     * Outputs the current canvas directly as JPEG.
     *
     * @return void
     * @TODO do we need to keep this?
     */
    public function toJpeg() : void
    {
        $color = imagecolorallocate($this->canvas, 255, 0, 0); //red

        imagerectangle(
            $this->canvas,
            $this->face['x'],
            $this->face['y'],
            $this->face['x'] + $this->face['w'],
            $this->face['y'] + $this->face['w'],
            $color
        );

        header('Content-type: image/jpeg');
        imagejpeg($this->canvas);
    }

    /**
     * Crops the face from the photo. Should be called after `detectFace`
     * method call. If a filename is provided, the face will be stored there,
     * otherwise it will be output to STDOUT.
     *
     * @param string|null $outFileName file name to store. If null, will be printed to output
     * @param int|null $margin to create a % of margin around the face
     * @return void
     * @throws Sensi\Facial\NoFaceException
     */
    public function cropFaceToJpeg(string $outFileName = null, $margin = 0 ) : void {
        if (empty($this->face)) {
            throw new NoFaceException('No face detected');
        }
        $canvas = imagecreatetruecolor( $this->face['w'] + ( $this->face[ 'w' ] * 2 * $margin ), $this->face['w'] + ( $this->face[ 'w' ] * 2 * $margin ) );
        imagecopy($canvas, $this->canvas, 0, 0, $this->face['x'] - ( $this->face['w'] * $margin ), $this->face['y'] - ( $this->face[ 'y' ] * $margin ), $this->face[ 'w' ] + ( $this->face[ 'w' ] * 2 * $margin ), $this->face['w'] + ( $this->face[ 'w' ] * 2 * $margin ) );
        if ($outFileName === null) {
            header('Content-type: image/jpeg');
        }
        imagejpeg($canvas, $outFileName);
    }

    public function toJson() : string
    {
        return json_encode($this->face);
    }

    public function getFace() :? array
    {
        return $this->face;
    }

    protected function doDetectGreedyBigToSmall(array $ii, array $ii2, int $width, int $height) :? array
    {
        $s_w = $width / 20.0;
        $s_h = $height / 20.0;
        $start_scale = $s_h < $s_w ? $s_h : $s_w;
        $scale_update = 1 / 1.2;
        for ($scale = $start_scale; $scale > 1; $scale *= $scale_update) {
            $w = round(20 * $scale) >> 0;
            $endx = $width - $w - 1;
            $endy = $height - $w - 1;
            $step = round(max($scale, 2)) >> 0;
            $inv_area = 1 / ($w*$w);
            for ($y = 0; $y < $endy; $y += $step) {
                for ($x = 0; $x < $endx; $x += $step) {
                    $passed = $this->detectOnSubImage($x, $y, $scale, $ii, $ii2, $w, $width + 1, $inv_area);
                    if ($passed) {
                        return ['x' => $x, 'y' => $y, 'w' => $w];
                    }
                } // end x
            } // end y
        }  // end scale
        return null;
    }

    protected function detectOnSubImage(int $x, int $y, float $scale, array $ii, array $ii2, int $w, int $iiw, float $inv_area) : bool
    {
        $mean = ($ii[($y + $w) * $iiw + $x + $w] + $ii[$y * $iiw + $x] - $ii[($y + $w) * $iiw + $x] - $ii[$y * $iiw + $x + $w]) * $inv_area;
        $vnorm = ($ii2[($y + $w) * $iiw + $x + $w]
                  + $ii2[$y * $iiw + $x]
                  - $ii2[($y + $w) * $iiw + $x]
                  - $ii2[$y * $iiw + $x + $w]) * $inv_area - ($mean * $mean);
        $vnorm = $vnorm > 1 ? sqrt($vnorm) : 1;
        $count_data = count($this->detection_data);
        for ($i_stage = 0; $i_stage < $count_data; $i_stage++) {
            $stage = $this->detection_data[$i_stage];
            $trees = $stage[0];
            $stage_thresh = $stage[1];
            $stage_sum = 0;
            $count_trees = count($trees);
            for ($i_tree = 0; $i_tree < $count_trees; $i_tree++) {
                $tree = $trees[$i_tree];
                $current_node = $tree[0];
                $tree_sum = 0;
                while ($current_node != null) {
                    $vals = $current_node[0];
                    $node_thresh = $vals[0];
                    $leftval = $vals[1];
                    $rightval = $vals[2];
                    $leftidx = $vals[3];
                    $rightidx = $vals[4];
                    $rects = $current_node[1];

                    $rect_sum = 0;
                    $count_rects = count($rects);

                    for ($i_rect = 0; $i_rect < $count_rects; $i_rect++) {
                        $s = $scale;
                        $rect = $rects[$i_rect];
                        $rx = round($rect[0] * $s + $x) >> 0;
                        $ry = round($rect[1] * $s + $y) >> 0;
                        $rw = round($rect[2] * $s) >> 0;
                        $rh = round($rect[3] * $s) >> 0;
                        $wt = $rect[4];
                        $r_sum = ($ii[($ry + $rh) * $iiw + $rx + $rw]
                                  + $ii[$ry * $iiw + $rx]
                                  - $ii[($ry + $rh) * $iiw + $rx]
                                  - $ii[$ry * $iiw + $rx + $rw]) * $wt;
                        $rect_sum += $r_sum;
                    }
                    $rect_sum *= $inv_area;
                    $current_node = null;
                    if ($rect_sum >= $node_thresh * $vnorm) {
                        if ($rightidx == -1) {
                            $tree_sum = $rightval;
                        } else {
                            $current_node = $tree[$rightidx];
                        }
                    } else {
                        if ($leftidx == -1) {
                            $tree_sum = $leftval;
                        } else {
                            $current_node = $tree[$leftidx];
                        }
                    }
                }
                $stage_sum += $tree_sum;
            }
            if ($stage_sum < $stage_thresh) {
                return false;
            }
        }
        return true;
    }
}
