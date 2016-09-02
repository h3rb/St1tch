<?php

 define( 'VERBOSE_OUTPUT', true );
 define( 'QUIET', true );

 define( 'G92_TEST_LENGTH', 5 );

 // St1tch
 // Takes a list of separately generated gcodes that have been marked with
 // Layer gcode boundary comments.  The coordinates must be in absolute mode.
 // Other rules about the gcode:
 //  - The input gcode must be for the solid volumes, no skirts
 //  - The skirt is tacked on to the beginning as the initial segment of
 //    a layer.
 //  - 

 // Example usage:
 // $file_c="Output-3models-verbose.gcode";
 // $merge_gcode = new GCodeLayerMerger( array( "al-baja.gcode", "al-golftree.gcode" ), $file_c );
 //
 // $merge_gcode = new GCodeLayerMerger( array( "test_cases/4/ship.gcode", "test_cases/4/truck.gcode" ), "Output.gcode" );
 // $merge_gcode = new GCodeLayerMerger( array( "1Truck.gcode", "1Wheels.gcode" ), "MyStitched.gcode" );

 $merge_gcode = new GCodeLayerMerger( array(
  "Bell.gcode",
  "Endstop1.gcode",
  "Endstop2.gcode",
//  "Present.gcode",
//  "Spacer1.gcode",
//  "Spacer2.gcode"
 ), $file_c );

 class GCodeLine {
  var $segments;
  var $comment;
  var $is_layer_transition_start;
  var $is_layer_transition_end;
  var $is_layers_end;
  var $is_layers_start;
  var $X,$Y,$E;
  var $G92;
  var $set_extruder;
  public function __construct($filename,$input,$comment=NULL) {
   $this->X=NULL;
   $this->Y=NULL;
   $this->G92=false;
   $this->segments=explode(" ",$input);
   $this->comment=$comment;
   if ( is_null($comment) && VERBOSE_OUTPUT ) $this->comment=$filename;
   $this->is_layer_transition_start=FALSE;
   $this->is_layer_transition_end=FALSE;
   $this->is_layers_end=FALSE;
   $this->is_layers_start=FALSE;
   if ( stripos($this->comment,'BEGINNING OF LAYER CHANGE') !== FALSE ) $this->is_layer_transition_start=TRUE;
   if ( stripos($this->comment,'END OF LAYER CHANGE') !== FALSE ) $this->is_layer_transition_end=TRUE;
   if ( $this->segments[0] == 'M107' ) $this->is_layers_end=TRUE;
   if ( $this->segments[0] == 'M82' ) $this->is_layers_start=TRUE;
   if ( $this->segments[0] == 'G92' ) $this->G92=true;
   foreach ( $this->segments as $s ) {
    $code=str_split($s);
    if ( $code[0] == 'E' || $code[0] == 'e' ) $this->E=floatval(str_replace(array('e','E'),'',$s));
   }
   if ( $this->segments[0] == 'G1' || $this->segments[0] == 'G0' ) {
    foreach ( $this->segments as $s ) {
     $code=str_split($s);
     if ( $code[0] == 'X' || $code[0] == 'x' ) $this->X=floatval(str_replace(array('x',"X"),'',$s));
     if ( $code[0] == 'Y' || $code[0] == 'y' ) $this->Y=floatval(str_replace(array('y',"Y"),'',$s));
    }
//    var_dump($this);
   }
  }
  public function Show() {
   $i=0;
   foreach ( $this->segments as $segment ) {
    echo '  '.$i.'> `'.$segment.'`'.PHP_EOL;
    $i++;
   }
   if ( $this->comment !== NULL ) echo 'Comment: '.$this->comment.PHP_EOL;
   if ( $this->is_layer_transition_start === TRUE )
    echo '------Layer Transition Start (Ending of Layer or Startup)------'.PHP_EOL;
   if ( $this->is_layer_transition_end === TRUE )
    echo '------Layer Transition End (Start of Layer)------'.PHP_EOL;
   if ( $this->is_layers_end === TRUE )
    echo '------Layers End Detected?------'.PHP_EOL;
   if ( $this->is_layers_start === TRUE )
    echo '------Layers Start Detected?------'.PHP_EOL;
  }
  public function Get() { return implode(" ",$this->segments) . ((strlen($this->comment) > 0) ? '     '.( stripos($this->comment,';') === FALSE ? (';'.$this->comment):$this->comment ) : ''); }
 };

 class GCodeSegment {
  var $block,$linecount;
  var $first_E,$last_E,$early_G92E;
  public function __construct( $arr=NULL ) {
   if ( $arr === NULL ) $arr=array();
   $this->block=$arr;
   $linecount=count($this->block);
   $this->first_E=NULL;
   $this->last_E=NULL;
   $this->early_G92E=false;
  }
  public function Show() {
   $i=0;
   foreach ( $this->gcode as $gcode ) {
    echo 'Line '.($i+1).':'.PHP_EOL;
    echo $gcode->Show();
    $i++;
   }
  }
  public function Analysis() {
   $this->linecount=count($this->block);
   $this->early_G92E=false;
   for ( $i = 0; $i < G92_TEST_LENGTH && $i < $this->linecount; $i++ ) {
    if ( $this->block[$i]->G92 && !is_null($this->block[$i]->E) ) { $this->early_G92E=true; break; }
   }
   foreach ( $this->block as $idx=>$gcode ) {
    if ( !is_null($gcode->E) ) {
     $this->first_E=$gcode->E;
     echo '1st E: '.$this->first_E.' ';
     break;
    }
   }
   $block_rev=array_reverse($this->block);
   foreach ( $block_rev as $idx=>$gcode ) {
    if ( !is_null($gcode->E) ) {
     $this->last_E=$gcode->E;
     echo 'Last E: '.$this->last_E.' ';
     break;
    }
   }
   if ( $this->early_G92E ) echo ' - G92 E'.$this->block[$i]->E.' on:'.($i+1).' (index='.$i.')'.PHP_EOL;
   else echo PHP_EOL;
  }
  public function Get() {
   $out='';
   foreach ( $this->block as $gcode ) {
    $out.=$gcode->Get()."\n";
   }
   return $out;
  }
 };

 class GCodeLayer {
  var $code,
   $is_last_layer,
   $is_first_layer;
  function __construct( $gcodesegment ) {
   $this->code=$gcodesegment;
   $this->is_last_layer=false;
   $this->is_first_layer=false;
  }
  function Analysis( $index, $layer_count ) {
   echo 'Layer: '.$index.' ';
   if ( $index == 0 ) { echo '(first layer) '; $this->is_first_layer=true; }
   if ( $index == $layer_count-1 ) { echo '(last layer) '; $this->is_last_layer=true; }
   $this->code->Analysis();
  }
 };

 class GCodeFile {
  var $filename,$lines,$startup_print,$shutdown_print,$layers,$layer_count,$least_X,$least_Y,$greatest_X,$greatest_Y;
  var $transitions;
  function __construct( $filename ) {
   $this->layer=array();
   $this->filename=$filename;
   $this->startup_print=new GCodeSegment();
   $this->shutdown_print=new GCodeSegment();
   $this->layers=array();
   $this->transitions=array();
   $input=explode("\n",str_replace("\r",'',file_get_contents($filename)));
   $lines=count($input);
   $found_layers_start=false;
   $found_layers_end=false;
   $buffered=new GCodeSegment();
   $transitioning=false;
   $i=0;
   echo '[';
   foreach ( $input as $line ) {
    $line=trim($line);
    $chars=str_split($line);
    if ( $chars[0] == ';' ) {
     $comment=$line;
     $line='';
    } else {
     $line=explode(';',$line);
     if ( isset($line[1]) ) $comment=$line[1];
     $line=trim($line[0]);
    }
    if ( isset($comment) ) $gcode=new GCodeLine($filename,$line,$comment); else $gcode=new GCodeLine($filename,$line);
//    $gcode->Show();
    if ( $found_layers_end ) {
     $buffered->block[]=$gcode;
     if ( !QUIET ) echo 'x';
    } else if ( $transitioning && !$gcode->is_layer_transition_end ) {
     $buffered->block[]=$gcode;
     echo '=';
    } else if ( !$found_layers_end && $gcode->is_layer_transition_start ) {
     if ( !$found_layers_start ) {
      $this->startup_print=$buffered;
      $found_layers_start=true;
      echo '!';
     } else {
      $this->layers[$i]=new GCodeLayer($buffered);
      $i++;
     }
     $buffered=new GCodeSegment();
     echo 'K';
     $transitioning=TRUE;
     $found_layers_start=TRUE;
     $buffered->block[]=$gcode;
    } else if ( !$found_layers_end && $gcode->is_layer_transition_end ) {
     $buffered->block[]=$gcode;
     $transitioning=FALSE;
     $this->transitions[]=$buffered;
     $buffered=new GCodeSegment();
     echo '>';
     if ( QUIET ) echo '...';
    } else if ( $gcode->is_layers_end && $found_layers_start ) {
     echo 'X';
     $found_layers_end=TRUE;
     $this->layers[$i]=new GCodeLayer($buffered);
     $i++;
     $buffered=new GCodeSegment();
     $buffered->block[]=$gcode;
    } else {
     if ( !QUIET ) echo 'g';
     $buffered->block[]=$gcode;
    }
    unset($comment);
   }
   echo ']';
   $this->shutdown_print=$buffered;
   echo PHP_EOL;
   $this->layer_count=count($this->layers);
   $this->Analysis();
  }
  function Analysis() {
   foreach ( $this->layers as $idx=>&$layer ) $layer->Analysis($idx,$this->layer_count);
  }
  function calculate_box_extents() {
   $this->least_X=NULL;
   $this->least_Y=NULL;
   $this->greatest_X=NULL;
   $this->greatest_Y=NULL;
   echo count($this->layers[1]->code->block).' lines on second layer'.PHP_EOL;
   foreach ( $this->layers as $layer ) foreach ( $layer->code->block as $line ) {
    if ( $line->X !== NULL && $line->Y !== NULL ) {
//     echo $line->X.','.$line->Y.PHP_EOL;
     if ( $this->least_X === NULL    || $line->X < $this->least_X    ) $this->least_X=$line->X;
     if ( $this->least_Y === NULL    || $line->Y < $this->least_Y    ) $this->least_Y=$line->Y;
     if ( $this->greatest_X === NULL || $line->X > $this->greatest_X ) $this->greatest_X=$line->X;
     if ( $this->greatest_Y === NULL || $line->Y > $this->greatest_Y ) $this->greatest_Y=$line->Y;
    }
   }
   echo '   -- Box Extents: '.$this->least_X.','.$this->least_Y.' to '.$this->greatest_X.','.$this->greatest_Y.PHP_EOL;
  }
  function Info( $talk=false ) {
   $this->calculate_box_extents();
   if ( $talk ) {
    echo 'File: `'.$this->filename.'` - '.$this->layer_count.' layers'.PHP_EOL;
    foreach ( $this->layers as $idx=>$l ) echo ' L ['.$idx.'] - '.count($l->block).' commands'.PHP_EOL;
   }
  }
 };

  function GCodeFileLayerCountCompare($a, $b) {
   return $a->layer_count == $b->layer_count ? 0 : ($a->layer_count < $b->layer_count ? -1 : 1);
  }

 class GCodeLayerMerger {
  var $filenames,$outfile;
  var $files,$file_count;
  var $skirt_path;
  var $merged;
  function __construct( $filenames_array, $out_filename ) {
   if ( count($filenames_array) <= 1 ) { echo 'GCodeLayerMerger: '.count($filenames_array).' files --> Nothing to merge!'.PHP_EOL; die; }
   $this->outfile=$out_filename;
   $this->filenames=$filenames_array;
   $this->files=array();
   foreach ( $this->filenames as $file ) {
    $this->files[]=new GCodeFile($file);
   }
   $this->file_count=count($this->files);
   $this->ShowMergeInfo();
   $this->determine_skirt_path();
   $this->merge_files();
   $this->output();
  }
  function ShowMergeInfo() {
   echo 'Merging '.count($this->filenames).' files and generate common skirt...'.PHP_EOL;
   $i=0;
   foreach ( $this->files as $f ) {
    $i++;
    echo $i.') ';
    $f->Info();
   }
   unset ($f);
  }
  function merge_files() {
   $this->merged=new GCodeSegment;
   $stop=FALSE;
   $layer=1;
   $most=0;
   usort($this->files, "GCodeFileLayerCountCompare");
   echo 'Files were sorted by layer count'.PHP_EOL;
   $i=0;
   foreach ( $this->files as $f ) {
    echo ($i+1).' > '.$f->layer_count.' layers in '.$f->filename.PHP_EOL;
    if ( $f->layer_count > $this->files[$most]->layer_count ) $most=$i;
    $i++;
   }
   unset($f);
   $largest_file_transitions=$this->files[$most]->transitions;
   $total_transitions=count($largest_file_transitions);
   echo 'Largest file '.($most+1).' (index='.$most.') `'.$this->files[$most]->filename.'` contains '.count($largest_file_transitions).' transitions '.PHP_EOL;
   $previous_layer=NULL;
   while ( !$stop ) {
    $written=0;
    $index=$layer-1;
    $fcount=0;
    echo ':';
    $this->merged->block[]=new GCodeLine($this->files[$most]->filename,';Inserting L-transition L:'.$index.' from tallest file');
    if ( $index < $total_transitions ) $this->merged->block[]=$largest_file_transitions[$index];
    foreach ( $this->files as $f ) {
     $fcount++;
     if ( $f->layer_count == 0 ) { echo '!'; continue; }
     if ( $layer > $f->layer_count ) { echo ' '; continue; }
//if ( defined(NEVER) ) {
////
     if ( !is_null($previous_layer) ) {
      $this->merged->block[]=new GCodeLine($f->filename,';----------------------------------['.$f->filename.']--L:'.$index);
      if ( $f->layers[$index]->code->early_G92E && $index > 0 ) { // Retraction, insert copied value from previous layer in file
       $gline=new GCodeLine($f->filename,"G92 E".$f->layers[$index-1]->code->last_E,"; INSERTED BY St1tch on index L:".$index);
       $this->merged->block[]=$gline;
       echo 'r';
      } else if ( $index > 0 ) { // No Retraction, reset value of E to previous layer in file
       $gline=new GCodeLine($f->filename,"G92 E".$f->layers[$index-1]->code->last_E,"; INSERTED BY St1tch on index L:".$index);
       $this->merged->block[]=$gline;
       echo 'n';
      }
     }
     foreach ( $f->layers[$index]->code->block as $gcode ) $this->merged->block[]=$gcode;
     $previous_layer=$f->layers[$index];
////
//} else {
//// Revised way
////
//}
     $written++;
     echo $fcount;
    } unset($f);
    $layer++;
    $stop=$written === 0;
   }
   echo 'Stopped at layer '.$layer.PHP_EOL;
//   file_put_contents( "just-layers.gcode", $this->merged->Get() );
//   echo 'Wrote '.$
  }
  function output() {
   $out='';
   // Write the startup code
    $out.=$this->files[0]->startup_print->Get();
   // Write the skirt path
   // Write the assembled scripts
    $out.=$this->merged->Get();
   // Write the shutdown code
    $out.=$this->files[count($this->files)-1]->shutdown_print->Get();
   // Write the file out
   file_put_contents($this->outfile,$out);
   echo 'Wrote '.$this->outfile.' ('.filesize($this->outfile).' bytes)'.PHP_EOL;
  }
  function determine_skirt_path() {
   $this->skirt_path=array();
   // generate a box profile.
   // expand each box from its center by the skirt gap width X 2.
   // .. walk through all of the bounding boxes finding intersections.
   // determine which edges are "outside" and which are "inside", discarding "inside"
   // organize the edges counter clockwise
   // walk the edges, and put the path into skirt_path
  }
 };

