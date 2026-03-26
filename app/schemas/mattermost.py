from pydantic import BaseModel


class SlashCommandResponse(BaseModel):
    response_type: str = "ephemeral"
    text: str = ""
    code: str = ""
