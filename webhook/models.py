"""
Pydantic models mirroring the MAX Bot API object schema.
Reference: https://dev.max.ru/docs-api/objects/Update
"""
from __future__ import annotations

from enum import Enum
from typing import Any, Optional

from pydantic import BaseModel, Field


# ---------------------------------------------------------------------------
# Enums
# ---------------------------------------------------------------------------

class UpdateType(str, Enum):
    message_created = "message_created"
    message_callback = "message_callback"
    message_edited = "message_edited"
    message_removed = "message_removed"
    bot_added_to_chat = "bot_added_to_chat"
    bot_removed_from_chat = "bot_removed_from_chat"
    user_added_to_chat = "user_added_to_chat"
    user_removed_from_chat = "user_removed_from_chat"
    bot_started = "bot_started"
    bot_stopped = "bot_stopped"
    dialog_cleared = "dialog_cleared"
    dialog_removed = "dialog_removed"
    message_chat_created = "message_chat_created"


class ChatType(str, Enum):
    dialog = "dialog"
    chat = "chat"
    channel = "channel"


class ChatAdminPermission(str, Enum):
    read_all_messages = "read_all_messages"
    add_remove_members = "add_remove_members"
    add_admins = "add_admins"
    change_chat_info = "change_chat_info"
    pin_message = "pin_message"
    edit_link = "edit_link"
    write = "write"
    edit = "edit"
    delete = "delete"
    can_call = "can_call"
    view_stats = "view_stats"


# ---------------------------------------------------------------------------
# Sub-objects
# ---------------------------------------------------------------------------

class User(BaseModel):
    user_id: int
    first_name: str
    last_name: Optional[str] = None
    username: Optional[str] = None
    is_bot: bool = False
    last_activity_time: Optional[int] = None

    @property
    def display_name(self) -> str:
        parts = [self.first_name]
        if self.last_name:
            parts.append(self.last_name)
        return " ".join(parts)


class Recipient(BaseModel):
    chat_id: Optional[int] = None
    chat_type: Optional[ChatType] = None
    user_id: Optional[int] = None


class Attachment(BaseModel):
    type: str
    payload: Optional[dict[str, Any]] = None


class MessageBody(BaseModel):
    mid: str
    seq: Optional[int] = None
    text: Optional[str] = None
    attachments: Optional[list[Attachment]] = None
    markup: Optional[list[Any]] = None


class LinkedMessage(BaseModel):
    type: Optional[str] = None
    sender: Optional[User] = None
    chat_id: Optional[int] = None
    message: Optional[MessageBody] = None


class Message(BaseModel):
    sender: Optional[User] = None
    recipient: Optional[Recipient] = None
    timestamp: Optional[int] = None
    body: Optional[MessageBody] = None
    link: Optional[LinkedMessage] = None
    stat: Optional[dict[str, Any]] = None
    url: Optional[str] = None

    def resolve_text(self) -> Optional[str]:
        """
        Returns the message text, falling back to the forwarded message's
        text when this message is itself empty (MAX puts forwarded content
        in `link.message.text`, leaving `body.text` blank).
        """
        if self.body and self.body.text:
            return self.body.text
        if self.link and self.link.message and self.link.message.text:
            return self.link.message.text
        return None


# ---------------------------------------------------------------------------
# Update (discriminated by update_type)
# ---------------------------------------------------------------------------

class Update(BaseModel):
    """
    Generic Update envelope. The full payload is preserved in `raw` so nothing
    is lost even if a field is not yet modelled.
    """
    update_type: UpdateType
    timestamp: int

    # Present on message_created
    message: Optional[Message] = None

    # Present on message_callback
    callback: Optional[dict[str, Any]] = None

    # Present on bot_added_to_chat / bot_removed_from_chat /
    #            user_added_to_chat / user_removed_from_chat
    chat_id: Optional[int] = None
    user: Optional[User] = None
    added_by_user: Optional[User] = None

    model_config = {"extra": "allow"}
