<?php

namespace RankShop;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use jojoe77777\FormAPI\SimpleForm;
use onebone\economyapi\EconomyAPI;
use _64FF00\PurePerms\PurePerms;

class Main extends PluginBase {

    private Config $config;
    private PurePerms $purePerms;
    private array $ranks = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();

        // Check for the PurePerms plugin
        $this->purePerms = $this->getServer()->getPluginManager()->getPlugin("PurePerms");
        if ($this->purePerms === null) {
            $this->getLogger()->error("§cPurePerms plugin not found! The plugin is shutting down.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Load ranks from the config
        $this->loadRanksFromConfig();

        $this->getLogger()->info("§aRankShop plugin successfully loaded!");
    }

    private function loadRanksFromConfig(): void {
        $ranksConfig = $this->config->get("ranks", []);
        if (empty($ranksConfig)) {
            $this->getLogger()->error("§cRanks are not configured in the config.yml file!");
            return;
        }

        foreach ($ranksConfig as $rankName => $rankData) {
            $price = $rankData["price"] ?? 0;
            $displayName = $rankData["display_name"] ?? $rankName;
            $info = $rankData["info"] ?? "No information available for this rank.";

            $this->ranks[$rankName] = [
                "price" => $price,
                "display_name" => $displayName,
                "info" => $info
            ];
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "rankshop") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cThis command can only be used by players.");
                return true;
            }

            $this->openRankShopUI($sender);
            return true;
        }
        return false;
    }

    private function openRankShopUI(Player $player): void {
        $form = new SimpleForm(function (Player $player, $data) {
            if ($data === null) return;

            $rankKey = array_keys($this->ranks)[$data];
            $this->openRankInfoUI($player, $rankKey);
        });

        $form->setTitle($this->config->get("ui-title", "RankShop"));
        $form->setContent($this->config->get("ui-description", "Select your desired rank!"));

        foreach ($this->ranks as $rankName => $data) {
            $form->addButton(str_replace(
                ["{rank}", "{price}"],
                [$data["display_name"], $data["price"]],
                $this->config->get("rank-button-format", "{rank}\n§ePrice: §a{price}")
            ));
        }

        $player->sendForm($form);
    }

    private function openRankInfoUI(Player $player, string $rankKey): void {
        $rankData = $this->ranks[$rankKey];
        $currentGroup = $this->purePerms->getUserDataMgr()->getGroup($player);

        $form = new SimpleForm(function (Player $player, $data) use ($rankKey, $currentGroup) {
            if ($data === null) {
                $this->openRankShopUI($player);
                return;
            }

            if ($data === 0) { // Purchase
                $rankData = $this->ranks[$rankKey];
                $price = $rankData["price"];
                $displayName = $rankData["display_name"];

                // Check if the player already has this rank
                if ($currentGroup !== null && $currentGroup->getName() === $rankKey) {
                    $player->sendMessage("§cYou already have this rank: §e" . $displayName);
                    return;
                }

                // Check if the player has enough money
                $playerMoney = EconomyAPI::getInstance()->myMoney($player);
                if ($playerMoney === null || $playerMoney < $price) {
                    $player->sendMessage(str_replace(
                        ["{rank}", "{price}"],
                        [$displayName, $price],
                        $this->config->get("not-enough-money-message", "§cYou don't have enough money to purchase {rank}! Required amount: §a{price}")
                    ));
                    return;
                }

                // Deduct money and assign rank
                EconomyAPI::getInstance()->reduceMoney($player, $price);
                $this->purePerms->setGroup($player, $this->purePerms->getGroup($rankKey));
                $player->sendMessage(str_replace(
                    ["{rank}"],
                    [$displayName],
                    $this->config->get("rank-purchase-success-message", "§aYou successfully purchased the {rank} rank!")
                ));
            } elseif ($data === 1) { // Back
                $this->openRankShopUI($player);
            }
        });

        $form->setTitle($rankData["display_name"]);
        $form->setContent($rankData["info"]);
        $form->addButton("§aPurchase");
        $form->addButton("§cBack");

        $player->sendForm($form);
    }
}
