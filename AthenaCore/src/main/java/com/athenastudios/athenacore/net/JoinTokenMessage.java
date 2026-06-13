package com.athenastudios.athenacore.net;

import io.netty.buffer.ByteBuf;
import net.minecraftforge.fml.common.network.ByteBufUtils;
import net.minecraftforge.fml.common.network.simpleimpl.IMessage;

/** Client -> Server: the single-use join token written by the launcher. */
public class JoinTokenMessage implements IMessage {
    public String token = "";

    public JoinTokenMessage() {
    }

    public JoinTokenMessage(String token) {
        this.token = token == null ? "" : token;
    }

    @Override
    public void fromBytes(ByteBuf buf) {
        this.token = ByteBufUtils.readUTF8String(buf);
    }

    @Override
    public void toBytes(ByteBuf buf) {
        ByteBufUtils.writeUTF8String(buf, this.token == null ? "" : this.token);
    }
}
