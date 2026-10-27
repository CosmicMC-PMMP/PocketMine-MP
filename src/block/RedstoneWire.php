<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\RedstoneWireConnectionType;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use function intdiv;

class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter{
	use AnalogRedstoneSignalEmitterTrait;
	use StaticSupportTrait;

	/**
	 * @var RedstoneWireConnectionType[]
	 * @phpstan-var array<int, RedstoneWireConnectionType>
	 */
	protected array $connections = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, 15, $this->signalStrength);

		//borrowed from RuntimeDataWriter::wallConnections
		$packed = 0;
		foreach(Facing::HORIZONTAL as $offset => $facing){
			$packed += match($this->getConnection($facing)){
				RedstoneWireConnectionType::NONE => 0,
				RedstoneWireConnectionType::SIDE => 1,
				RedstoneWireConnectionType::UP => 2,
			} * (3 ** $offset);
		}
		$w->boundedIntAuto(0, (3 ** 4) - 1, $packed);
		foreach(Facing::HORIZONTAL as $offset => $facing){
			$this->connections[$facing] = match(intdiv($packed, 3 ** $offset) % 3){
				0 => RedstoneWireConnectionType::NONE,
				1 => RedstoneWireConnectionType::SIDE,
				2 => RedstoneWireConnectionType::UP,
				default => throw new AssumptionFailedError("Unreachable")
			};
		}
	}

	/**
	 * @param int $facing one of Facing::NORTH/EAST/SOUTH/WEST
	 */
	public function getConnection(int $facing) : RedstoneWireConnectionType{
		return $this->connections[$facing] ?? RedstoneWireConnectionType::NONE;
	}

	/**
	 * @param int $facing one of Facing::NORTH/EAST/SOUTH/WEST
	 * @return $this
	 */
	public function setConnection(int $facing, RedstoneWireConnectionType $type) : self{
		if($facing !== Facing::NORTH && $facing !== Facing::SOUTH && $facing !== Facing::WEST && $facing !== Facing::EAST){
			throw new \InvalidArgumentException("Facing can only be north, east, south or west");
		}
		$this->connections[$facing] = $type;
		return $this;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}elseif($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN)->hasEdgeSupport();
	}

	public function onPostPlace() : void{
		$this->updateDiagonalWires();
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		$this->updateDiagonalWires();
		return $result;
	}

	/**
	 * Wires stepping up or down to this one aren't direct neighbours, so they don't get a block update when it changes.
	 * TODO: this isn't done when the wire is removed without being broken (e.g. by explosions)
	 */
	private function updateDiagonalWires() : void{
		$world = $this->position->getWorld();
		foreach(Facing::HORIZONTAL as $facing){
			$side = $this->position->getSide($facing);
			foreach([Facing::UP, Facing::DOWN] as $vertical){
				$block = $world->getBlock($side->getSide($vertical));
				if($block instanceof RedstoneWire && $block->recalculateConnections()){
					$world->setBlock($block->position, $block);
				}
			}
		}
	}

	private static function isConductor(Block $block) : bool{
		return $block->isFullCube() && !$block->isTransparent();
	}

	/**
	 * TODO: check connections to nearby other redstone components
	 */
	private function getConnectionTowards(int $facing, bool $conductorAbove) : RedstoneWireConnectionType{
		$neighbour = $this->getSide($facing);
		if(!$conductorAbove && $neighbour->getSide(Facing::UP)->hasSameTypeId($this)){
			return $neighbour->getSupportType(Facing::opposite($facing)) === SupportType::FULL ?
				RedstoneWireConnectionType::UP :
				RedstoneWireConnectionType::SIDE;
		}
		if($neighbour->hasSameTypeId($this) || (!self::isConductor($neighbour) && $neighbour->getSide(Facing::DOWN)->hasSameTypeId($this))){
			return RedstoneWireConnectionType::SIDE;
		}
		return RedstoneWireConnectionType::NONE;
	}

	private function recalculateConnections() : bool{
		$conductorAbove = self::isConductor($this->getSide(Facing::UP));
		$connections = [];
		foreach(Facing::HORIZONTAL as $facing){
			$connections[$facing] = $this->getConnectionTowards($facing, $conductorAbove);
		}

		$northSouth = $connections[Facing::NORTH] !== RedstoneWireConnectionType::NONE || $connections[Facing::SOUTH] !== RedstoneWireConnectionType::NONE;
		$eastWest = $connections[Facing::EAST] !== RedstoneWireConnectionType::NONE || $connections[Facing::WEST] !== RedstoneWireConnectionType::NONE;
		foreach(Facing::HORIZONTAL as $facing){
			$crossAxisConnected = Facing::axis($facing) === Axis::X ? $northSouth : $eastWest;
			if(!$crossAxisConnected && $connections[$facing] === RedstoneWireConnectionType::NONE){
				$connections[$facing] = RedstoneWireConnectionType::SIDE;
			}
		}

		$changed = false;
		foreach($connections as $facing => $connection){
			if($connection !== $this->getConnection($facing)){
				$this->setConnection($facing, $connection);
				$changed = true;
			}
		}
		return $changed;
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}
