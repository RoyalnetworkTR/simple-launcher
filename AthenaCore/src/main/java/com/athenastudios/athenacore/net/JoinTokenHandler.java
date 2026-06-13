package com.athenastudios.athenacore.net;

import com.athenastudios.athenacore.AthenaConfig;
import com.athenastudios.athenacore.AthenaCore;
import com.athenastudios.athenacore.server.ServerEvents;
import com.athenastudios.athenacore.util.HttpUtil;
import com.google.gson.JsonObject;
import net.minecraft.entity.player.EntityPlayerMP;
import net.minecraft.util.text.TextComponentString;
import net.minecraftforge.fml.common.FMLCommonHandler;
import net.minecraftforge.fml.common.network.simpleimpl.IMessage;
import net.minecraftforge.fml.common.network.simpleimpl.IMessageHandler;
import net.minecraftforge.fml.common.network.simpleimpl.MessageContext;

/**
 * Server-side handler: verifies the join token against the backend off-thread,
 * then either marks the player verified or disconnects them (on the main thread).
 */
public class JoinTokenHandler implements IMessageHandler<JoinTokenMessage, IMessage> {
    @Override
    public IMessage onMessage(JoinTokenMessage message, MessageContext ctx) {
        final EntityPlayerMP player = ctx.getServerHandler().player;
        final String token = message.token;

        // If no server key is configured we don't enforce (e.g. integrated/LAN).
        if (AthenaConfig.serverKey == null || AthenaConfig.serverKey.isEmpty()) {
            FMLCommonHandler.instance().getMinecraftServerInstance()
                    .addScheduledTask(() -> ServerEvents.markVerified(player.getUniqueID()));
            return null;
        }

        new Thread(() -> {
            boolean ok = false;
            String reason = "Doğrulama başarısız.";
            try {
                JsonObject body = new JsonObject();
                body.addProperty("join_token", token);
                body.addProperty("username", player.getName());
                JsonObject res = HttpUtil.postJson(
                        AthenaConfig.backendUrl + "/api/join/verify", body, AthenaConfig.serverKey);
                if (res != null && res.has("ok") && res.get("ok").getAsBoolean()) {
                    ok = true;
                } else if (res != null && res.has("reason") && !res.get("reason").isJsonNull()) {
                    reason = "Giriş reddedildi: " + res.get("reason").getAsString();
                }
            } catch (Throwable t) {
                AthenaCore.LOGGER.warn("[AthenaCore] join verify error", t);
                reason = "Sunucu doğrulama hatası.";
            }

            final boolean fok = ok;
            final String freason = reason;
            FMLCommonHandler.instance().getMinecraftServerInstance().addScheduledTask(() -> {
                if (fok) {
                    ServerEvents.markVerified(player.getUniqueID());
                } else if (player.connection != null) {
                    player.connection.disconnect(new TextComponentString(freason));
                }
            });
        }, "AthenaCore-Verify").start();

        return null;
    }
}
