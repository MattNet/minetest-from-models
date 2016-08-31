#!/usr/bin/php -q
<?php
###
# Converts a raw-triangle format of a 3D model into a minetest worldedit schematic
###
# PROGRAM FLOW
# open and read the input file
# determine the highest and lowest value for each direction
# shoot a ray from (x,y) in direction z
# gather the intersections with any/all triangles
# sort all intersections from smallest to largest
# iterate through the intersections
# - toggle an IS_AN_INTERIOR flag with each iteration
# if the IS_AN_INTERIOR flag is "on"
# - iterate down the z axis for the length of the intersection
# - - lay a minetest node
# repeat shooting rays until all x/y numbers have been used
# write the file
#
# Note that this is intentionally a course-grained increment:
# - The nodes can only be packed so densely (1 node is ~ 1 meter in size)
# Decreasing granularity (to a fraction of 1) allows more chances that the edges 
#   of the model will be detected early. This will make the model blockier
###
# Inputs:
# - The file to convert
###
# Outputs
# - writes a minetest worldedit schematic file (.we)
###
# WorldEdit Schematic file (.we) format:
# - entire file is a single line
# - begins with "return { ", ends with " }"
# - comma deliminated
# - each node is described thusly:
# - - { ["y"] = <XX>, ["x"] = <XX>, ["name"] = "<mod>:<nodename>", ["z"] = <XX>, ["meta"] = {
# - - ["inventory"] = {  }, ["fields"] = {  } }, ["param2"] = 0, ["param1"] = 0 }
###
# for base algorithm, see: https://en.wikipedia.org/wiki/M%C3%B6ller%E2%80%93Trumbore_intersection_algorithm
###

###
# Configuration items
###
$GRANULARITY = 1; // how dense the rays are that generate minetest nodes. Smaller values have a higher computation cost
$NODE_NAME = "default:dirt"; // Minetest node to use on model interior
$SHOW_PROGRESS = true; // Set to true to emit progress messages on stdout
$ROUND_INPUT_NUMBERS = true; // set to true to round the triangle's coordinates to near-integers. Should reduce computation cost
$METHOD = 1; // set to 1 for Brute-force method. Set to 0 for run-length method

###
# Main Program
###

if( ! isset($argv[1]) || ! is_readable($argv[1]) )
{
  echo "\nConverts a raw-triangle format of a 3D model into a minetest worldedit schematic.\n\n";
  echo "Called by:\n";
  echo "  ".$argv[0]."  FILE/TO/CONVERT\n\n";
  exit(1);
}

$input = "";	// contains the text of the input file
$raw = array(); // holds the triangle coordinates. format is [triangle][coordinate]=(float)
$maxX = 0;	// maximum extent in the +X direction
$minX = 8192;	// maximum extent in the -X direction
$maxY = 0;	// maximum extent in the +Y direction
$minY = 8192;	// maximum extent in the -Y direction
$maxZ = 0;	// maximum extent in the +Z direction
$minZ = 8192;	// maximum extent in the -Z direction
$outputArray = array(); // collects those spaces where a node is written
$outputText = "return { "; // the contents of the final converted file
$progressIncrementor = 0; // this is incremented on every ray. used to determine when to show more progress bar
$raw = array(); // raw set of triangle coords

if( $SHOW_PROGRESS )
  echo "Opening and reading '{$argv[1]}'.\n";

// open the input file
$input = file($argv[1]);
// read the input file 
foreach( $input as $key=>$trianglePoints )
{
  $trianglePoints = trim($trianglePoints);
  $raw[$key] = explode( " ", $trianglePoints );
}
unset( $input, $trianglePoints ); // unload the input file from memory

if( $SHOW_PROGRESS )
  echo "Finding maximum and minimum extants of the model\n";

// determine the highest and lowest value for each direction
foreach( $raw as $triNum )
{
  // iterate through the points for each triangle
  for( $i=0; $i<9; $i+=3 )
  {
    // round the triangle coords to one decimal point
    if( $ROUND_INPUT_NUMBERS )
    {
      $triNum[$i]   = round($triNum[$i],  1);
      $triNum[$i+1] = round($triNum[$i+1],1);
      $triNum[$i+2] = round($triNum[$i+2],1);
    }

    // subtract/add $GRANULARITY to the end of min/max to provide space 
    // to find an intersection against the edges defined by min/max
    if( $triNum[$i] > $maxX )
      $maxX = $triNum[$i]+$GRANULARITY;
    if( $triNum[$i] < $minX )
      $minX = $triNum[$i]-$GRANULARITY;
    if( $triNum[$i+1] > $maxY )
      $maxY = $triNum[$i+1]+$GRANULARITY;
    if( $triNum[$i+1] < $minY )
      $minY = $triNum[$i+1]-$GRANULARITY;
    if( $triNum[$i+2] > $maxZ )
      $maxZ = $triNum[$i+2]+$GRANULARITY;
    if( $triNum[$i+2] < $minZ )
      $minZ = $triNum[$i+2]-$GRANULARITY;
  }
}

if( $SHOW_PROGRESS )
{
  // determine size of progress bar:
  $rayTotal = ceil($maxX - $minX) * ceil($maxY - $minY); // the number of rows
  $rayTotal /= ($GRANULARITY^2); // the number of rays per row

  // report progress
  echo "Model bound by ($minX,$minY,$minZ) -> ($maxX,$maxY,$maxZ)\nNow shooting ".number_format($rayTotal)." rays.\n";
  echo "If this takes too long, try increasing \$GRANULARITY. Currently shooting ".round(1/$GRANULARITY,2)." rays per row.\n";
}

// shoot a ray from (x,y) in direction z
for( $x=$minX; $x<=$maxX; $x+=$GRANULARITY )
{
  for( $y=$minY; $y<=$maxY; $y+=$GRANULARITY )
  {
    $intersectionDistance = array();
    $IS_AN_INTERIOR = false; // flag for determining if we are inside a model, thus should/not place nodes
    $distKey = 0; // key to $intersectionDistance, shows distance of the next intersection

    // iterate through all of the triangles to see if the ray intersects some of them
    foreach( $raw as $triNum )
    {
      // determine if there is a hit with the current triangle
      $result = detectIntersection(
                                    array( $triNum[0], $triNum[1], $triNum[2] ),
                                    array( $triNum[3], $triNum[4], $triNum[5] ),
                                    array( $triNum[6], $triNum[7], $triNum[8] ),
                                    array( $x, $y, $minZ ),	// ray start
                                    array( $x, $y, $maxZ )	// ray end
                                  );
      // if there was a hit, record the distance along the ray's z axis
      if( $result !== false )
        $intersectionDistance[] = $minZ + $result * $maxZ;
    }

    if( empty($intersectionDistance) )
    {
      if( $SHOW_PROGRESS )
        echo "\r".number_format($progressIncrementor++)." rays";
      continue;
    }

    // sort $intersectionDistance from lowest to highest
    sort( $intersectionDistance );

###
# Run-Length approach: Iterate through the intersections and then iterate through part of the Z axis
###
if( $METHOD == 0 )
{
    // iterate through the intersections with this ray
    foreach( $intersectionDistance as $key=>$dist )
    {
      if( $IS_AN_INTERIOR == true && $key!=0 )
      {
        // the distance along the ray at the last key, in units of $GRANULARITY
        $z = floor( $intersectionDistance[($key-1)] );
        // iterate through the z axis
        for( ; $z<=$dist; $z+=$GRANULARITY )
        {
          // set these to FLOOR'd values, because Minetest only deals with integer values in it's coordinates
          $tempX = floor($x);
          $tempY = floor($y);
          $tempZ = floor($z);

          // set the various nested arrays if not yet set
          if( ! isset( $outputArray[$tempX] ) )
            $outputArray[$tempX] = array();
          if( ! isset( $outputArray[$tempX][$tempY] ) )
            $outputArray[$tempX][$tempY] = array();
          if( ! isset( $outputArray[$tempX][$tempY][$tempZ] ) )
            $outputArray[$tempX][$tempY][$tempZ] = array();
          // finally get to set the coordinate to true
          $outputArray[$tempX][$tempY][$tempZ] = true;
        }
        $IS_AN_INTERIOR = false;
      }
      else
      {
        $IS_AN_INTERIOR = true;
      }
    }
}
###
# Brute-force approach: iterate through the entire Z axis, looking for intersections
###
if( $METHOD == 1 )
{
    // iterate through the z axis
    for( $z=$minZ; $z<=$maxZ; $z+=$GRANULARITY )
    {
      // when $z >= next intersection, flip the $IS_AN_INTERIOR flag
      if( isset($intersectionDistance[$distKey]) && $z >= $intersectionDistance[$distKey] )
      {
        if( $IS_AN_INTERIOR == true )
          $IS_AN_INTERIOR = false;
        else
          $IS_AN_INTERIOR = true;
        $distKey++; // increment $distKey to the next intersection
      }

      // if $IS_AN_INTERIOR == true, write a node
      if( $IS_AN_INTERIOR )
      {
        // collects those spaces where a node is written. Capture here instead
        // of direct-write in order to prevent multiple writes to the same node

        // set these to FLOOR'd values, because Minetest only deals with integer values in it's coordinates
        $tempX = floor($x);
        $tempY = floor($y);
        $tempZ = floor($z);

        // set the various nested arrays if not yet set
        if( ! isset( $outputArray[$tempX] ) )
          $outputArray[$tempX] = array();
        if( ! isset( $outputArray[$tempX][$tempY] ) )
          $outputArray[$tempX][$tempY] = array();
        if( ! isset( $outputArray[$tempX][$tempY][$tempZ] ) )
          $outputArray[$tempX][$tempY][$tempZ] = array();
        // finally get to set the coordinate to true
        $outputArray[$tempX][$tempY][$tempZ] = true;
      }
    }
}
###
    if( $SHOW_PROGRESS )
      echo "\r".number_format($progressIncrementor++)." rays";
  }
}

unset( $raw ); // unload the raw set of triangles from memory

if( $SHOW_PROGRESS )
  echo "\n".count($outputArray, COUNT_RECURSIVE)." nodes will be generated.\nGenerating schematic file from intersections.\n";

foreach( $outputArray as $x=>$xOutput )
{
  foreach( $xOutput as $y=>$zOutput )
  {
    foreach( $xOutput as $z=>$doWrite )
    {
      if( $doWrite )
      {
        $outputText .= '{ ["y"] = '.$y.', ["x"] = '.$x.', ["name"] = "'.$NODE_NAME.'", ["z"] = '.$z.', ';
        $outputText .= '["meta"] = { ["inventory"] = {  }, ["fields"] = {  } }, ["param2"] = 0, ["param1"] = 0 }, ';
      }
    }
  }
}

unset( $outputArray ); // unload the set of logical node locations

// trim off the last comma
$outputText = rtrim( $outputText, ", " );
// add the closing brace
$outputText .= " }";

if( $SHOW_PROGRESS )
  echo "\nWriting schematic file.\n";

// write the file. filename is simply the input filename with ".we" appended
file_put_contents( $argv[1].".we", $outputText );

return; // program end


###
# Detects an intersection between the given triangle and the given ray
# Pulled from https://en.wikipedia.org/wiki/M%C3%B6ller%E2%80%93Trumbore_intersection_algorithm
###
# Inputs
# - (array) first point of the triangle. values are floats
# - (array) second point of the triangle. values are floats
# - (array) third point of the triangle. values are floats
# 
###
# Outputs
# - (float) The distance along the ray that the intersection occurred
#   Returns false if there is no intersenction
###
function detectIntersection( $firstTriPoint, $secondTriPoint, $thirdTriPoint, $rayOrigin, $rayDirection )
{
  // a number that is really close to another number, but not the same
  $EPSILON = 0.000001;

  // find the vectors for two edges
  $firstEdge = vectorSubtraction( $secondTriPoint, $firstTriPoint );
  $nextEdge = vectorSubtraction( $thirdTriPoint, $firstTriPoint );

  // determine the Determinant
  $P = crossProduct( $rayDirection, $nextEdge );
  $determinant = dotProduct( $firstEdge, $P );

  // if determinant is near zero, ray lies in plane of triangle or ray is parallel to plane of triangle
  if( $determinant > -1*$EPSILON && $determinant < $EPSILON )
    return false;
  $inverseDeterminant = 1 / $determinant;

  // calculate distance from $firstTriPoint to ray origin
  $originDist = vectorSubtraction( $rayOrigin, $firstTriPoint );

  // Calculate u parameter
  $u = dotProduct( $originDist, $P ) * $inverseDeterminant;

  // testing bound: the intersection lies outside of the triangle
  if( $u < 0 || $u > 1 )
    return false;

  $Q = crossProduct( $originDist, $firstEdge );

  // Calculate V parameter
  $v = dotProduct( $rayDirection, $Q ) * $inverseDeterminant;

  // testing bound: the intersection lies outside of the triangle
  if( $v < 0 || ($u + $v)  > 1 )
    return false;

  $t = dotProduct( $nextEdge, $Q ) * $inverseDeterminant;

  // ray intersection
  if( $t > $EPSILON )
    return $t;

  // A line intersection, but not a ray intersection
  return false;
}

###
# Cross-product two vectors
###
# Inputs
# - (array) The first point. Values are floats
# - (array) The second point. Values are floats
###
# Outputs
# - (array) the X, Y, and Z values after the operation
###
function crossProduct( $first, $second )
{
  return array(
                ( ($first[1]*$second[2]) - ($first[2]*$second[1]) ),
                ( ($first[2]*$second[0]) - ($first[0]*$second[2]) ),
                ( ($first[0]*$second[1]) - ($first[1]*$second[0]) )
              );
}

###
# Dot-product two vectors
###
# Inputs
# - (array) The first point. Values are floats
# - (array) The second point. Values are floats
###
# Outputs
# - (float) the value after the operation
###
function dotProduct( $first, $second )
{
  return (
           ($first[0]*$second[0]) +
           ($first[1]*$second[1]) +
           ($first[2]*$second[2])
         );
}

###
# Subtracts vectors (element-wise)
###
# Inputs
# - (array) The minuend point. The point being subtracted from. Values are floats
# - (array) The subtrahend point. The point being removed from the other. Values are floats
###
# Outputs
# - (array) the X, Y, and Z values after subtraction
###
function vectorSubtraction( $minuend, $subtrahend )
{
  return array(
                ($minuend[0]-$subtrahend[0]),
                ($minuend[1]-$subtrahend[1]),
                ($minuend[2]-$subtrahend[2])
              );
}
?>
