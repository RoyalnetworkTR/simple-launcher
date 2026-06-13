package com.athenastudios.athenacore;

import com.athenastudios.athenacore.net.JoinTokenHandler;
import com.athenastudios.athenacore.net.JoinTokenMessage;
import net.minecraftforge.fml.common.Mod;
import net.minecraftforge.fml.common.Mod.EventHandler;
import net.minecraftforge.fml.common.SidedProxy;
import net.minecraftforge.fml.common.event.FMLInitializationEvent;
import net.minecraftforge.fml.common.event.FMLPreInitializationEvent;
import net.minecraftforge.fml.common.network.NetworkRegistry;
import net.minecraftforge.fml.common.network.simpleimpl.SimpleNetworkWrapper;
import net.minecraftforge.fml.relauncher.Side;
import org.apache.logging.log4j.Logger;

/**
 * AthenaCore - single client+server mod that wires the Minecraft server to the
 * Athena backend: launcher join-token verification, custom skins and ban
 * enforcement. The server stays offline-mode; the join token is the auth proof.
 */
@Mod(modid = AthenaCore.MODID, name = AthenaCore.NAME, version = AthenaCore.VERSION,
        acceptableRemoteVersions = "*")
public class AthenaCore {
    public static final String MODID = "athenacore";
    public static final String NAME = "AthenaCore";
    public static final String VERSION = "1.0.0";

    /** Channel name must be <= 20 chars. */
    public static final String CHANNEL_NAME = "athenacore";

    public static SimpleNetworkWrapper CHANNEL;
    public static Logger LOGGER;

    @SidedProxy(
            clientSide = "com.athenastudios.athenacore.ClientProxy",
            serverSide = "com.athenastudios.athenacore.ServerProxy")
    public static CommonProxy proxy;

    @EventHandler
    public void preInit(FMLPreInitializationEvent event) {
        LOGGER = event.getModLog();
        AthenaConfig.load(event.getSuggestedConfigurationFile());

        CHANNEL = NetworkRegistry.INSTANCE.newSimpleChannel(CHANNEL_NAME);
        // Client -> Server: the launcher's single-use join token.
        CHANNEL.registerMessage(JoinTokenHandler.class, JoinTokenMessage.class, 0, Side.SERVER);

        proxy.preInit(event);
    }

    @EventHandler
    public void init(FMLInitializationEvent event) {
        proxy.init(event);
    }
}
